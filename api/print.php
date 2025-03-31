<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Image;
use Photobooth\Processor\PrintProcessor;
use Photobooth\Service\LoggerService;
use Photobooth\Service\PrintManagerService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));
$processor = null;

try {
    if (empty($_GET['filename'])) {
        throw new \Exception('No file provided!');
    }

    $printManager = PrintManagerService::getInstance();
    if ($printManager->isPrintLocked()) {
        throw new \Exception($config['print']['limit_msg']);
    }

    $imageHandler = new Image();
    $imageHandler->debugLevel = $config['dev']['loglevel'];
    $vars['randomName'] = $imageHandler->createNewFilename('random');
    $vars['fileName'] = $_GET['filename'];
    $vars['uniqueName'] = substr($vars['fileName'], 0, -4) . '-' . $vars['randomName'];
    $vars['sourceFile'] = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $vars['fileName'];
    $vars['printFile'] = FolderEnum::PRINT->absolute() . DIRECTORY_SEPARATOR . $vars['uniqueName'];

    $status = false;

    // exit with error if file does not exist
    if (!file_exists($vars['sourceFile'])) {
        throw new \Exception('File ' . $vars['fileName'] . ' not found.');
    }
} catch (\Exception $e) {
    // Handle the exception
    $data = ['error' => $e->getMessage()];
    $logger->error($e->getMessage());
    echo json_encode($data);
    die();
}

$privatePrintApi = PathUtility::getAbsolutePath('private/api/print.php');
if (is_file($privatePrintApi)) {
    $logger->debug('Using private/api/print.php.');

    try {
        include $privatePrintApi;
    } catch (\Exception $e) {
        $logger->error('Error (private print API): ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

if (!file_exists($vars['printFile'])) {
    try {
        $source = $imageHandler->createFromImage($vars['sourceFile']);
        if (!$source) {
            throw new \Exception('Invalid image resource');
        }
        if (class_exists('Photobooth\Processor\PrintProcessor')) {
            $processor = new PrintProcessor($imageHandler, $logger, $printManager, $vars, $config);
        }
        if ($processor !== null && $processor instanceof PrintProcessor && method_exists($processor, 'preProcessing')) {
            list($imageHandler, $vars, $config, $source) = $processor->preProcessing($imageHandler, $vars, $config, $source);
        }

        // rotate image if needed
        if (imagesx($source) > imagesy($source) || $config['print']['no_rotate'] === true) {
            $imageHandler->qrRotate = false;
        } else {
            $source = imagerotate($source, 90, 0);
            $imageHandler->qrRotate = true;
            if (!$source) {
                throw new \Exception('Cannot rotate image resource.');
            }
        }

        if ($config['print']['print_frame']) {
            $imageHandler->framePath = $config['print']['frame'];
            $imageHandler->frameExtend = false;
            $source = $imageHandler->applyFrame($source);
            if (!$source instanceof \GdImage) {
                throw new \Exception('Failed to apply frame to image resource.');
            }
        }

        if ($config['print']['qrcode']) {
            // create qr code
            if ($config['ftp']['enabled'] && $config['ftp']['useForQr'] && isset($config['ftp']['processedTemplate'])) {
                $imageHandler->qrUrl = $config['ftp']['processedTemplate'] . DIRECTORY_SEPARATOR . $vars['fileName'];
            } elseif ($config['qr']['append_filename']) {
                $imageHandler->qrUrl = PathUtility::getPublicPath($config['qr']['url'] . $vars['fileName'], true);
            } else {
                $imageHandler->qrUrl = PathUtility::getPublicPath($config['qr']['url'], true);
            }
            $imageHandler->qrSize = $config['print']['qrSize'];
            $imageHandler->qrMargin = $config['print']['qrMargin'];
            $imageHandler->qrColor = $config['print']['qrBgColor'];
            $imageHandler->qrOffset = $config['print']['qrOffset'];
            $imageHandler->qrPosition = $config['print']['qrPosition'];

            $qrCode = $imageHandler->createQr();
            if (!$qrCode instanceof \GdImage) {
                throw new \Exception('Cannot create QR Code resource.');
            }
            $source = $imageHandler->applyQr($qrCode, $source);
            if (!$source instanceof \GdImage) {
                throw new \Exception('Cannot apply QR Code to image resource.');
            }
            unset($qrCode);
        }

        if ($config['textonprint']['enabled']) {
            $imageHandler->fontSize = $config['textonprint']['font_size'];
            $imageHandler->fontRotation = $config['textonprint']['rotation'];
            $imageHandler->fontLocationX = $config['textonprint']['locationx'];
            $imageHandler->fontLocationY = $config['textonprint']['locationy'];
            $imageHandler->fontColor = $config['textonprint']['font_color'];
            $imageHandler->fontPath = $config['textonprint']['font'];
            $imageHandler->textLine1 = $config['textonprint']['line1'];
            $imageHandler->textLine2 = $config['textonprint']['line2'];
            $imageHandler->textLine3 = $config['textonprint']['line3'];
            $imageHandler->textLineSpacing = $config['textonprint']['linespace'];

            $source = $imageHandler->applyText($source);
            if (!$source instanceof \GdImage) {
                throw new \Exception('Failed to apply text to image resource.');
            }
        }

        if ($config['print']['crop']) {
            $source = $imageHandler->resizeCropImage($source, $config['print']['crop_width'], $config['print']['crop_height']);
            if (!$source instanceof \GdImage) {
                throw new \Exception('Failed to crop image resource.');
            }
        }
        
        // Gatekeeper by Stefan
        // Get the original dimensions
        $origWidth  = imagesx($source);
        $origHeight = imagesy($source);

        // Check if the image has a 4:3 aspect ratio (allowing a small tolerance)
        $expectedRatio = 4 / 3;
        $actualRatio   = $origWidth / $origHeight;
        if (abs($actualRatio - $expectedRatio) > 0.01) {
            die("Error: Image must be 4:3. (Found ratio: $actualRatio)");
        }

        // --- Create a 4:6 composite by duplicating the 4:3 image vertically ---
        //
        // For a 4:3 image (e.g. 400×300), duplicating vertically yields 400×600,
        // which corresponds to a 4×6 print (if you consider 400 as the “4‐inch” side).
        $newCompositeHeight = $origHeight * 2;
        $composite = imagecreatetruecolor($origWidth, $newCompositeHeight);
        if (!$composite) {
            die("Error: Could not create composite image.");
        }

        // Copy the original image into the top half
        if (!imagecopy($composite, $source, 0, 0, 0, 0, $origWidth, $origHeight)) {
            die("Error: Could not copy image to top half.");
        }
        // Copy the original image into the bottom half
        if (!imagecopy($composite, $source, 0, $origHeight, 0, 0, $origWidth, $origHeight)) {
            die("Error: Could not copy image to bottom half.");
        }

        // --- Add borders ---
        //
        // We add 100 pixels to the left and right and 200 pixels to the top and bottom.
        $borderLR = ($origWidth * 0.05) / 2; // left/right border in pixels
        $borderTB = ($newCompositeHeight * 0.02) / 2; // top/bottom border in pixels

        $finalWidth  = $origWidth + 2 * $borderLR;
        $finalHeight = $newCompositeHeight + 2 * $borderTB;

        $finalImage = imagecreatetruecolor($finalWidth, $finalHeight);
        if (!$finalImage) {
            die("Error: Could not create final image with borders.");
        }

        // Fill the final image with a border color (here black)
        $white = imagecolorallocate($finalImage, 0, 0, 0);
        imagefill($finalImage, 0, 0, $white);

        // Copy the composite image onto the final canvas at the proper offset
        if (!imagecopy($finalImage, $composite, $borderLR, $borderTB, 0, 0, $origWidth, $newCompositeHeight)) {
            die("Error: Could not copy composite image onto final canvas.");
        }

        $source = $finalImage;

        imagedestroy($source);
        imagedestroy($composite);
        imagedestroy($finalImage);

        // End of Stefan

        if ($processor !== null && $processor instanceof PrintProcessor && method_exists($processor, 'postProcessing')) {
            list($imageHandler, $vars, $config, $source) = $processor->postProcessing($imageHandler, $vars, $config, $source);
        }
        $imageHandler->jpegQuality = 100;
        if (!$imageHandler->saveJpeg($source, $vars['printFile'])) {
            throw new \Exception('Cannot save print image.');
        }

        // clear cache
        unset($source);
    } catch (\Exception $e) {
        // Try to clear cache
        if ($source instanceof \GdImage) {
            unset($source);
        }

        $data = ['error' => $e->getMessage()];
        $logger->error($e->getMessage());
        echo json_encode($data);
        die();
    }
}

$pageSizes = [
    "4x4" => "w288h288",
    "2x4*2" => "w288h288-div2",
    "4x3" => "w288h216",
    "4x4+4x2" => "w288h288_w288h144",
    "4x6" => "w288h432",
    "4x3*2" => "w288h432-div2",
    "2x4*3" => "w288h432-div3",
    "4x8" => "w288h576",
    "4x6+4x2" => "w288h432_w288h144",
    "4x3*2+4x2" => "w288h432-div2_w288h144",
    "4x4*2" => "w288h576-div2",
    "2x4*4" => "w288h576-div4",
    "4.5x3" => "w324h216",
    "4.5x4" => "w324h288",
    "4.5x4.5" => "w324h324",
    "4.5x6" => "w324h432",
    "4.5x3*2" => "w324h432-div2",
    "4.5x6.75" => "w324h486",
    "4.5x2*3" => "w324h432-div3",
    "4.5x8" => "w324h576",
    "4.5x4*2" => "w324h576-div2",
    "4.5x2*4" => "w324h576-div4",
    "4.5x6+4.5x2" => "w324h432_w324h144", 
    "4.5x3*2+4.5x2" => "w324h432-div2_w324h144"
];

// print image
$status = 'ok';
$cmd = sprintf($config['commands']['print'], $vars['printFile']);
$cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

exec($cmd, $output, $returnValue);

$printManager->addToPrintDb($vars['fileName'], $vars['uniqueName']);

$linecount = 0;
if ($config['print']['limit'] > 0) {
    $linecount = $printManager->getPrintCountFromDB();
    $linecount = $linecount ? $linecount : 0;
    if ($linecount % $config['print']['limit'] == 0) {
        if ($printManager->lockPrint()) {
            $status = 'locking';
        } else {
            $logger->error('Error creating the file ' . $printManager->printLockFile);
        }
    }
    file_put_contents($printManager->printCounter, $linecount);
}

$data = [
    'status' => $status,
    'count' => $linecount,
    'msg' => $cmd,
    'returnValue' => $returnValue,
    'output' => $output,
];
$logger->debug('data', $data);
echo json_encode($data);
exit();
