<?php 
require_once('site-settings.php'); 
require_once('project-pricing.php');
require_once('schema-generator.php');


// Parse SEO-friendly URL
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query parameters
$path = parse_url($requestUri, PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));
$lastPart = end($parts);

if (is_numeric($lastPart)) {
    $projectID = $lastPart;
} else if (isset($_GET['ProjectID'])) {
    // Backward compatibility for old URLs
    $projectID = $_GET['ProjectID'];
 }
//  else {
//     include '404.php';
//     exit;
// }


// Extract unit type from URL slug (e.g. "2-bedroom-binghatti-hillcrest")
$slug = prev($parts);
$numberOfBedrooms = 0;
$specialType = '';

if (preg_match('/(\d+)/', $slug, $matches)) {
    // e.g. "2-bedroom" → 2
    $numberOfBedrooms = (int)$matches[1];
} else {
    // Handle text-based unit types (studio, penthouse, office)
    $slugLower = strtolower($slug);
    if (strpos($slugLower, 'studio') !== false) {
        $specialType = 'studio';
    } elseif (strpos($slugLower, 'penthouse') !== false) {
        $specialType = 'penthouse';
    } elseif (strpos($slugLower, 'office') !== false) {
        $specialType = 'office';
    }
}


// Load data.json

/**
 * Load and decode JSON
 */
$loadJsonFile = function (string $path): ?array {
    if (!file_exists($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }
    if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $contents = substr($contents, 3);
    }
    $decoded = json_decode($contents, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log(sprintf('JSON decode error for %s: %s', $path, json_last_error_msg()));
        return null;
    }
    return $decoded;
};

$data = [];
$dataPathCandidates = [__DIR__ . '/files/data.json', __DIR__ . '/data.json'];
$dataPath = null;
$detailDataPathCandidates = [__DIR__ . '/files/project-detail-data.json', __DIR__ . '/project-detail-data.json'];
$detailDataPath = null;
$detailData = [];
$detailIndex = [];
$tabConfig = [
    'GoogleMapEmbedURL' => [
        'label' => 'LOCATION',
        'tab' => 'project-tab1',
        'type' => 'content'
    ],
    'PaymentPlan' => [
        'label' => 'PAYMENT',
        'tab' => 'project-tab6',
        'type' => 'content'
    ],
    'GallerySlides' => [
        'label' => 'GALLERY',
        'tab' => 'project-tab2',
        'type' => 'content'
    ],
    'BrochureSlides' => [
        'label' => 'BROCHURE',
        'tab' => 'project-tab3',
        'type' => 'content'
    ],
    'StatusVideoUrl' => [
        'label' => 'STATUS',
        'tab' => 'project-tab4',
        'type' => 'content'
    ]
];
$detailExtraFields = ['GoogleMapEmbedURL', 'GoogleMapTitle','GoogleLocationURL', 'PaymentPlanImage', 'StatusVideoUrl', 'GallerySlides', 'BrochureSlides', 'ProjectDescription', 'UpcomingProject'];
$soldOut = false;

foreach ($dataPathCandidates as $candidatePath) {
    if (file_exists($candidatePath)) {
        $dataPath = $candidatePath;
        break;
    }
}

foreach ($detailDataPathCandidates as $candidatePath) {
    if (file_exists($candidatePath)) {
        $detailDataPath = $candidatePath;
        break;
    }
}

$hasInventoryData = $dataPath !== null;

if ($dataPath !== null) {
    $json = $loadJsonFile($dataPath);
    if ($json === null) {
        die('Unable to decode data.json at ' . htmlspecialchars($dataPath));
    }
    $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
}

if ($detailDataPath !== null) {
    $jsonDetail = $loadJsonFile($detailDataPath);
    if ($jsonDetail === null) {
        die('Unable to decode project-detail-data.json at ' . htmlspecialchars($detailDataPath));
    }
    $rawDetailData = (isset($jsonDetail['data']) && is_array($jsonDetail['data'])) ? $jsonDetail['data'] : [];
    $detailData = [];

    foreach ($rawDetailData as $projectEntry) {
        if (!isset($projectEntry['ProjectID'])) {
            continue;
        }

        $projectContext = $projectEntry;
        unset($projectContext['Units']);

        $projectUnits = (isset($projectEntry['Units']) && is_array($projectEntry['Units'])) ? $projectEntry['Units'] : [];

        if (empty($projectUnits)) {
            $detailData[] = $projectContext;
            continue;
        }

        foreach ($projectUnits as $unitEntry) {
            if (!is_array($unitEntry)) {
                continue;
            }
            // Merge project-level info with unit-level details to maintain the existing structure expectations.
            $detailData[] = array_merge($projectContext, $unitEntry);
        }
    }

    foreach ($detailData as $detailItem) {
        if (!isset($detailItem['ProjectID'])) {
            continue;
        }

        $projectKey = (int) $detailItem['ProjectID'];
        $unitKey = isset($detailItem['UnitTypeID']) ? (int) $detailItem['UnitTypeID'] : 0;

        if (!isset($detailIndex[$projectKey])) {
            $detailIndex[$projectKey] = [];
        }

        $detailIndex[$projectKey][$unitKey] = $detailItem;
    }
}

$detailProjectExists = isset($detailIndex[(int) $projectID]) && !empty($detailIndex[(int) $projectID]);

// Check if ProjectID exists in data.json
$projectExists = false;
foreach ($data as $item) {
    if (isset($item['ProjectID']) && $item['ProjectID'] == $projectID) {
        $projectExists = true;
        break;
    }
}

// Find UnitTypeID by ProjectID and slug

$unitTypeID = null;
$unitTypeName = null;

if (isset($_GET['UnitTypeID']) && is_numeric($_GET['UnitTypeID'])) {
    $unitTypeID = (int) $_GET['UnitTypeID'];
}

$activeDataset = $data;

if ($unitTypeID === null) {
    foreach ($data as $item) {
        if ($item['ProjectID'] == $projectID) {
            if (
                ($numberOfBedrooms > 0 && (int)$item['NumberOfBedrooms'] == $numberOfBedrooms) ||
                ($specialType && stripos($item['UnitTypeName'], $specialType) !== false)
            ) {
                $unitTypeID = $item['UnitTypeID'];
                $unitTypeName = $item['UnitTypeName'];
                break;
            }
        }
    }

    if ($unitTypeID === null && !empty($detailData)) {
        foreach ($detailData as $item) {
            if ($item['ProjectID'] == $projectID) {
                if (
                    ($numberOfBedrooms > 0 && (int)$item['NumberOfBedrooms'] == $numberOfBedrooms) ||
                    ($specialType && stripos($item['UnitTypeName'], $specialType) !== false)
                ) {
                    $unitTypeID = $item['UnitTypeID'];
                    $unitTypeName = $item['UnitTypeName'];
                    if ($hasInventoryData) {
                        $soldOut = true;
                    }
                    $activeDataset = $detailData;
                    break;
                }
            }
        }
    }
}

// Get all matching data by ProjectID + UnitTypeID

$filteredData = [];
$unitMatchFound = false;

if ($unitTypeID) {
    if ($projectExists) {
        foreach ($data as $item) {
            if ($item['ProjectID'] == $projectID && $item['UnitTypeID'] == $unitTypeID) {
                $filteredData[] = $item;
                $activeDataset = $data;
                $unitMatchFound = true;
                break;
            }
        }
    }

    if (!$unitMatchFound && $detailProjectExists) {
        foreach ($detailData as $item) {
            if ($item['ProjectID'] == $projectID && isset($item['UnitTypeID']) && $item['UnitTypeID'] == $unitTypeID) {
                $filteredData[] = $item;
                $activeDataset = $detailData;
                if ($hasInventoryData) {
                    $soldOut = true;
                }
                $unitMatchFound = true;
                break;
            }
        }
    }
}

if (!$unitMatchFound && $projectExists) {
    foreach ($data as $item) {
        if ($item['ProjectID'] == $projectID) {
            $filteredData[] = $item;
            $activeDataset = $data;
            $unitMatchFound = true;
            break;
        }
    }
}

if (!$unitMatchFound && !$projectExists && $detailProjectExists) {
    foreach ($detailData as $item) {
        if ($item['ProjectID'] == $projectID) {
            $filteredData[] = $item;
            $activeDataset = $detailData;
            if ($hasInventoryData) {
                $soldOut = true;
            }
            $unitMatchFound = true;
            break;
        }
    }
}

$data = $activeDataset;


// Apply detail data extras

$applyDetailExtras = function (array &$record) use ($detailIndex, $detailExtraFields) {
    if (empty($record)) {
        return;
    }

    $projectId = isset($record['ProjectID']) ? (int) $record['ProjectID'] : null;
    if ($projectId === null || empty($detailIndex[$projectId])) {
        return;
    }

    $unitTypeId = isset($record['UnitTypeID']) ? (int) $record['UnitTypeID'] : 0;
    $projectDetailEntries = $detailIndex[$projectId];

    $detailMatch = $projectDetailEntries[$unitTypeId] ?? ($projectDetailEntries[0] ?? null);
    if (!$detailMatch) {
        foreach ($projectDetailEntries as $entry) {
            $detailMatch = $entry;
            break;
        }
    }

    if (!$detailMatch) {
        return;
    }

    foreach ($detailExtraFields as $fieldName) {
        if (array_key_exists($fieldName, $detailMatch)) {
            $record[$fieldName] = $detailMatch[$fieldName];
        }
    }
};

foreach ($filteredData as &$filteredItem) {
    $applyDetailExtras($filteredItem);
}
unset($filteredItem);

// Use first matching record as base (same as your old $projectData)

$projectData = !empty($filteredData) ? $filteredData[0] : null;

if (!$projectData) {
    include '404.php';
    exit;
}

// Hide upcoming projects until they go live.
$upcomingProject = false;
if (array_key_exists('UpcomingProject', $projectData)) {
    $value = $projectData['UpcomingProject'];
    if (is_bool($value)) {
        $upcomingProject = $value;
    } elseif (is_numeric($value)) {
        $upcomingProject = ((int) $value) === 1;
    } elseif (is_string($value)) {
        $valueLower = strtolower(trim($value));
        $upcomingProject = in_array($valueLower, ['1', 'true', 'yes'], true);
    } else {
        $upcomingProject = (bool) $value;
    }
}

if ($upcomingProject && !$projectExists) {
    include '404.php';
    exit;
}

// Convert handover date from milliseconds

preg_match('/\d+/', $projectData['HandoverDate'], $matches);
$timestamp_ms = $matches[0] ?? 0;
$timestamp = $timestamp_ms / 1000;
$date = date("Y-m-d", $timestamp);


// Extract project fields

$projectName = $projectData['ProjectName'] ?? '';
$unitTypeID = $projectData['UnitTypeID'] ?? '';
$unitTypeName = $projectData['UnitTypeName'] ?? '';
$numberOfBedrooms = $projectData['NumberOfBedrooms'] ?? '';
$startingArea = $projectData['StartingArea'] ?? '';
$startingPrice = $projectData['StartingPrice'] ?? '';
$googleLocationURL = $projectData['GoogleLocationURL'] ?? '';
$community = $projectData['Community'] ?? '';
$projectCategory = $projectData['ProjectCategory'] ?? '';
$handoverDate = $date ?? '';
$unitCount = $projectData['UnitCount'] ?? '';
$googleMapEmbedURL = $projectData['GoogleMapEmbedURL'] ?? '';
$googleMapTitle = $projectData['GoogleMapTitle'] ?? 'Location map of ' . $projectName;

$projectMapEmbedHtml = '';
$hasLocationTab = false;
if (!empty($googleMapEmbedURL)) {
    $mapSrc = htmlspecialchars($googleMapEmbedURL, ENT_QUOTES, 'UTF-8');
    $mapTitle = htmlspecialchars($googleMapTitle, ENT_QUOTES, 'UTF-8');
    $projectMapEmbedHtml = '<iframe src="' . $mapSrc . '" width="100%" height="500" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="' . $mapTitle . '"></iframe>';
    $hasLocationTab = true;
}

$paymentPlanImage = $projectData['PaymentPlanImage'] ?? '';
$gallerySlides = $projectData['GallerySlides'] ?? [];
$brochureSlides = $projectData['BrochureSlides'] ?? [];
$statusVideoUrl = $projectData['StatusVideoUrl'] ?? '';
$projectDescription = $projectData['ProjectDescription'] ?? '';

$getGallerySlideAlt = function ($slide) use ($projectName) {
    $defaultAlt = !empty($projectName) ? trim($projectName) . ' gallery image' : 'Project gallery image';

    if (!is_array($slide)) {
        return $defaultAlt;
    }

    $rawAlt = $slide['altText'] ?? '';
    if (is_string($rawAlt)) {
        $rawAlt = trim($rawAlt);
    } else {
        $rawAlt = '';
    }
    if ($rawAlt !== '') {
        return $rawAlt;
    }

    $headingHtml = $slide['headingHtml'] ?? '';
    if (is_string($headingHtml) && $headingHtml !== '') {
        $normalized = preg_replace('/<br\s*[^>]*>/i', ' ', $headingHtml);
        $headingText = trim(preg_replace('/\s+/', ' ', strip_tags($normalized)));
        if ($headingText !== '') {
            $words = explode(' ', $headingText);
            foreach ($words as &$word) {
                if ($word === '') {
                    continue;
                }
                if (preg_match('/^[A-Z0-9]+$/', $word)) {
                    $word = ucfirst(strtolower($word));
                }
            }
            unset($word);
            $headingText = trim(implode(' ', $words));
            if ($headingText !== '') {
                return $headingText;
            }
        }
    }

    return $defaultAlt;
};

$renderProjectDescription = function ($text) {
    if (!is_string($text)) {
        $text = '';
    }

    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    if ($escaped === '') {
        return $escaped;
    }

    $allowedTags = ['br', 'b', 'strong', 'em', 'i', 'u'];
    foreach ($allowedTags as $tag) {
        if ($tag === 'br') {
            $escaped = preg_replace('/&lt;br\s*\/?&gt;/i', '<br>', $escaped);
            continue;
        }

        $pattern = '/&lt;(\/?)\s*' . $tag . '\s*&gt;/i';
        $escaped = preg_replace_callback($pattern, function ($matches) use ($tag) {
            $isClosing = trim($matches[1]) === '/';
            return $isClosing ? '</' . $tag . '>' : '<' . $tag . '>';
        }, $escaped);
    }

    return $escaped;
};
$paymentPlanContent = '';
if (!empty($paymentPlanImage)) {
    $planImg = '<img class="image-auto" src="' . htmlspecialchars($paymentPlanImage, ENT_QUOTES, 'UTF-8') . '" alt="payment plan">';    
    $paymentPlanContent = $planImg;    
}

$galleryTabContent = '';
if (!empty($gallerySlides) && is_array($gallerySlides)) {
    ob_start();
    ?>
    <div class="project-details-slider-tabs">
        <div class="project-details-tab-slider">
            <?php foreach ($gallerySlides as $index => $slide):
                $slideClass = 'project-details-tab-slider-content';
                if ($index === 0) {
                    $slideClass .= ' project-details-tab-slide-active';
                }
                $slideImage = htmlspecialchars($slide['image'] ?? '', ENT_QUOTES, 'UTF-8');
                $headingHtml = $slide['headingHtml'] ?? '';
                $slideAltText = htmlspecialchars($getGallerySlideAlt($slide), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="<?= $slideClass ?>">
                <div class="project-details-tab-zoom-image" style="background-image:url('<?= $slideImage ?>');">
                    <img src="<?= $slideImage ?>" alt="<?= $slideAltText ?>" class="visually-hidden" loading="lazy">
                </div>
                <?php if (!empty($headingHtml)): ?>
                    <h2><?= $headingHtml ?></h2>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="project-details-tab-slider-arrow-left"><i class="fa-thin fa-angle-left"></i><svg width="35" fill="#fff" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M50.3 261.7c-3.1-3.1-3.1-8.2 0-11.3l176-176c3.1-3.1 8.2-3.1 11.3 0s3.1 8.2 0 11.3L67.3 256 237.7 426.3c3.1 3.1 3.1 8.2 0 11.3s-8.2 3.1-11.3 0l-176-176z"></path></svg></div>
        <div class="project-details-tab-slider-arrow-right"><i class="fa-thin fa-angle-right"></i><svg width="35" fill="#fff" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M269.7 250.3c3.1 3.1 3.1 8.2 0 11.3l-176 176c-3.1 3.1-8.2 3.1-11.3 0s-3.1-8.2 0-11.3L252.7 256 82.3 85.7c-3.1-3.1-3.1-8.2 0-11.3s8.2-3.1 11.3 0l176 176z"></path></svg></div>
    </div>
    <?php
    $galleryTabContent = ob_get_clean();
}

$brochureTabContent = '';
if (!empty($brochureSlides) && is_array($brochureSlides)) {
    ob_start();
    ?>
    <div class="project-details-slider-tabs">
        <div class="project-details-tab-slider">
            <?php foreach ($brochureSlides as $index => $slide):
                $slideClass = 'project-details-tab-slider-content';
                if ($index === 0) {
                    $slideClass .= ' project-details-tab-slide-active';
                }
                $slideImage = htmlspecialchars($slide['image'] ?? '', ENT_QUOTES, 'UTF-8');
                $slideLink = htmlspecialchars($slide['link'] ?? '#', ENT_QUOTES, 'UTF-8');
                $buttonText = htmlspecialchars($slide['buttonText'] ?? 'DOWNLOAD', ENT_QUOTES, 'UTF-8');
            ?>
            <div class="<?= $slideClass ?>">
                <div class="project-details-tab-zoom-image" style="background-image:url('<?= $slideImage ?>');"></div>
                <a class="custom-btn" target="_blank" rel="noopener" href="<?= $slideLink ?>"><?= $buttonText ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="project-details-tab-slider-arrow-left"><i class="fa-thin fa-angle-left"></i><svg width="35" fill="#fff" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M50.3 261.7c-3.1-3.1-3.1-8.2 0-11.3l176-176c3.1-3.1 8.2-3.1 11.3 0s3.1 8.2 0 11.3L67.3 256 237.7 426.3c3.1 3.1 3.1 8.2 0 11.3s-8.2 3.1-11.3 0l-176-176z"></path></svg></div>
        <div class="project-details-tab-slider-arrow-right"><i class="fa-thin fa-angle-right"></i><svg width="35" fill="#fff" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M269.7 250.3c3.1 3.1 3.1 8.2 0 11.3l-176 176c-3.1 3.1-8.2 3.1-11.3 0s-3.1-8.2 0-11.3L252.7 256 82.3 85.7c-3.1-3.1-3.1-8.2 0-11.3s8.2-3.1 11.3 0l176 176z"></path></svg></div>
    </div>
    <?php
    $brochureTabContent = ob_get_clean();
}

$statusVideoContent = '';
if (!empty($statusVideoUrl)) {
    $videoSrc = htmlspecialchars($statusVideoUrl, ENT_QUOTES, 'UTF-8');
    $statusVideoContent = '<iframe class="youtube-video" style=" width: 100%; height: 500px; border: 0;" width="100%" height="500" src="' . $videoSrc . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
}

$tabContents = [
    'GoogleMapEmbedURL' => $projectMapEmbedHtml,
    'PaymentPlan' => $paymentPlanContent,
    'GallerySlides' => $galleryTabContent,
    'BrochureSlides' => $brochureTabContent,
    'StatusVideoUrl' => $statusVideoContent
];

$availableTabs = [];
foreach ($tabConfig as $fieldName => $tabMeta) {
    $content = $tabContents[$fieldName] ?? '';
    if (!empty($content)) {
        $tabMeta['content'] = $content;
        $availableTabs[] = $tabMeta;
    }
}

if (!$hasLocationTab) {
    $googleLocationURL = '';
}

// Get the appropriate price and currency based on visitor's country
$isUK = CountryDetection::isUK();

function formatPriceDisplay($uaePrice, $isUK) {
    $formattedUae = number_format($uaePrice, 0, '.', ',');
    if ($isUK) {
        $ukPrice = ProjectPricing::convertUaeToUkPrice($uaePrice);
        $formattedUk = number_format($ukPrice, 0, '.', ',');
        return "Starting AED {$formattedUae} | £ {$formattedUk}*";
    }
    return "Starting AED {$formattedUae}";
}

// dynamic meta title and description
$projectNameFormatted = ucwords(strtolower($projectName));

$unitType = '';
if (stripos($unitTypeName, 'office') !== false) {
    $unitType = 'Office';
} elseif (stripos($unitTypeName, 'penthouse') !== false) {
    $unitType = 'Penthouse';
} elseif (stripos($unitTypeName, 'studio') !== false) {
    $unitType = 'Studio';
} elseif ($numberOfBedrooms > 0) {
    $unitType = $numberOfBedrooms . ' Bedroom';
} else {
    $unitType = 'Unit';
}

if ($isUK) {
    // UK Version
    if ($unitType === 'Office' || $unitType === 'Penthouse') {
        $META_TITLE = "{$unitType} for sale in {$projectNameFormatted} | Binghatti UK";
    } else {
        $META_TITLE = "{$unitType} Flat for sale in {$projectNameFormatted} | Binghatti UK";
    }
    $ukPrice = ProjectPricing::convertUaeToUkPrice($startingPrice);
    $formattedPrice = '£ ' . number_format($ukPrice, 0, '.', ',');
    if ($unitType === 'Office' || $unitType === 'Penthouse') {
        $META_DESCRIPTION = "Check {$unitType} for sale at {$projectNameFormatted} in {$community} with luxury amenities and flexible payment plans, Starting at {$formattedPrice}.";
    } else {
        $META_DESCRIPTION = "Check {$unitType} flat for sale at {$projectNameFormatted} in {$community} with luxury amenities and flexible payment plans, Starting at {$formattedPrice}.";
    }
} else {
    // EN Version
    if ($unitType === 'Office' || $unitType === 'Penthouse') {
        $META_TITLE = "{$unitType} for sale in {$projectNameFormatted}";
    } else {
        $META_TITLE = "{$unitType} Apartment for sale in {$projectNameFormatted}";
    }
    $formattedPrice = 'AED ' . number_format($startingPrice, 0, '.', ',');
    if ($unitType === 'Office' || $unitType === 'Penthouse') {
        $META_DESCRIPTION = "View {$unitType} for sale at {$projectNameFormatted} in {$community} with luxury amenities and flexible payment plans, Starting at {$formattedPrice}.";
    } else {
        $META_DESCRIPTION = "View {$unitType} Apartment for sale at {$projectNameFormatted} in {$community} with luxury amenities and flexible payment plans, Starting at {$formattedPrice}.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <?php addHreflangTags(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="<?= htmlspecialchars($META_DESCRIPTION, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($META_TITLE, ENT_QUOTES, 'UTF-8') ?></title>
  <?php
  getUnitProductSchema(
      $projectNameFormatted,        
      $community,                       
      $numberOfBedrooms,                
      $startingPrice,                   
      $projectID,                       
      $unitTypeID,                      
      $META_TITLE,                    
      $META_DESCRIPTION
  );
  ?>
  <link rel="icon" type="image/png" sizes="32x32" href="https://binghattiweb.imgix.net/favicon.png">
  <link rel="apple-touch-icon" sizes="180x180" href="https://binghattiweb.imgix.net/favicon.png">
  <meta name="msapplication-TileImage" content="https://binghattiweb.imgix.net/favicon.png">
  
  <!-- Preload optimized based on screen -->
  <link rel="preload" as="video" href="https://binghattiweb.imgix.video/hero-190724.mp4?fm=mp4" type="video/mp4" media="(min-width: 768px)">
  <link rel="preload" as="video" href="https://binghattiweb.imgix.video/hero-mobile.mp4?fm=mp4" type="video/mp4" media="(max-width: 767px)">

  <!-- Load essential framework and animations first -->
  <link rel="preload" href="<?= url(); ?>/assets/css/bootstrap-grid.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="<?= url(); ?>/assets/css/animate.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="<?= url(); ?>/assets/css/flatpickr.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  
  <!-- Flickity Styles -->
  <link rel="stylesheet" href="https://unpkg.com/flickity@2/dist/flickity.min.css">

  <!-- Main and responsive styles -->
  <link rel="stylesheet" href="<?= url(); ?>/assets/css/style-min.css?var=1.0.4">
  <!-- <link rel="preload" href="assets/css/style.css" as="style" onload="this.onload=null;this.rel='stylesheet'"> -->
  <link rel="preload" href="<?= url(); ?>/assets/css/responsive-min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">

  <link rel="preload" as="font" href="<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Hair.woff2" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" as="font" href="<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Light.woff2" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" as="font" href="<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Regular.woff2" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" as="font" href="<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Medium.woff2" type="font/woff2" crossorigin="anonymous">
  <link rel="preload" as="font" href="<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Bold.woff2" type="font/woff2" crossorigin="anonymous">
  
  <style>
    .project-details-slider-tabs {
      width: 100%;
      position: relative;
    }

    .project-details-tab-slider {
      width: 100%;
      min-height: inherit;
      overflow: hidden;
    }

    .project-details-tab-slider .project-details-tab-slider-content {
      width: 100%;
      margin-right: 0;
    }   
    .project-details-tab-slider .flickity-button,
    .project-details-tab-slider .flickity-page-dots {
      display: none;
    }
       
    /* Desktop & tablets (>= 768px) */
    @media only screen and (min-width: 768px) {
        .project-details-slider-tabs .flickity-viewport
        {
       height:730px
        }
    }  

 @media only screen and (max-width: 768px) {
        .project-details-slider-tabs {
            --tab-mobile-media-height: 35vh;
        }
        .project-details-slider-tabs,
        .project-details-slider-tabs .project-details-tab-slider,
        .project-details-slider-tabs .project-details-tab-slider-content,
        .project-details-slider-tabs .project-details-tab-slider-content h2 {
            height: auto !important;
        }
        /* The image area should be 35vh on mobile */
        .project-details-slider-tabs .project-details-tab-zoom-image {
            height: var(--tab-mobile-media-height) !important;
            min-height: var(--tab-mobile-media-height) !important;
            display: block !important;
            overflow: hidden;
        }
        /* Use flex column so: image on top, heading below */
        .project-details-tab-slider-content {
            display: flex !important;
            flex-direction: column !important;
            padding: 0 !important;
            justify-content: flex-start;    
        }
        /* Heading below the image */
        .project-details-tab-slider-content h2 {
            margin-bottom: 0 !important;
            height: auto !important;
            font-size: 14px;
            line-height: 1.35;
            position: relative;
            padding-top: 10px;
        }
        .project-details-tab-slider-content h2 b {
            display: inline !important;    
            font-size: 18px;
        }
        .project-details-tab-slider-content .custom-btn {
            position: static !important;
            align-self: center;
            margin: 15px auto 0;
            text-align: center;
        }
        .project-details-tab-slider-arrow-left,
        .project-details-tab-slider-arrow-right {
            height:var(--tab-mobile-media-height) !important;
            top: calc(var(--tab-mobile-media-height) / 2);
            transform: translateY(-50%);
        }
    }
  </style>

  <style>
    @font-face {
      font-family: 'AktivGrotesk';
      src: url('<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Hair.woff2') format('woff2');
      font-weight: 100;
      font-style: normal;
      font-display: swap;
    }

    @font-face {
      font-family: 'AktivGrotesk';
      src: url('<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Light.woff2') format('woff2');
      font-weight: 300;
      font-style: normal;
      font-display: swap;
    }

    @font-face {
      font-family: 'AktivGrotesk';
      src: url('<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Regular.woff2') format('woff2');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }

    @font-face {
      font-family: 'AktivGrotesk';
      src: url('<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Medium.woff2') format('woff2');
      font-weight: 500;
      font-style: normal;
      font-display: swap;
    }

    @font-face {
      font-family: 'AktivGrotesk';
      src: url('<?= url(); ?>/assets/fonts/aktiv-grotesk/AktivGroteskCorp-Bold.woff2') format('woff2');
      font-weight: 700;
      font-style: normal;
      font-display: swap;
    }
    .sold-out-badge{
        display:inline-block;
        margin-top: 5px;
        font-weight:600;
        color:red;
    }
  </style>

  <!-- add new -->
  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400&display=swap" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400&display=swap"></noscript>

  <!-- Fallback for older browsers -->
  <noscript>
    <link rel="stylesheet" href="<?= url(); ?>/assets/css/bootstrap-grid.min.css">
    <link rel="stylesheet" href="<?= url(); ?>/assets/css/animate.min.css">
    <link rel="stylesheet" href="https://unpkg.com/flickity@2/dist/flickity.min.css">
    <link rel="stylesheet" href="<?= url(); ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= url(); ?>/assets/css/responsive-min.css">
  </noscript>
  
  <!-- Google Tag Manager -->
  <script>
    window.dataLayer = window.dataLayer || [];
    window.gtmLoadInitiated = false;
    
    function loadGTM() {
      if (window.gtmLoadInitiated) return;
      window.gtmLoadInitiated = true;
      
      (function(w,d,s,l,i){
        w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});
        var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
        j.async=true;
        j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
        f.parentNode.insertBefore(j,f);
      })(window,document,'script','dataLayer','GTM-PWVCGKH');
    }
    
    // Load GTM after 5 seconds
    setTimeout(loadGTM, 5000);
    
    // Also load if user interacts with the page
    document.addEventListener('click', loadGTM);
    document.addEventListener('scroll', loadGTM);
    document.addEventListener('keydown', loadGTM);
  </script>
 
</head>
<body>
  
  <!-- Noscript fallback (loads immediately if JS is disabled) -->
  <noscript>
    <iframe src="https://www.googletagmanager.com/ns.html?id=GTM-PWVCGKH" height="0" width="0" style="display:none;visibility:hidden"></iframe>
  </noscript>
  <!-- End Google Tag Manager -->
  
  <!-- Menu -->
  <?php require_once ('menu.php'); ?>

  <input type="hidden" name="projectID" class="project_ID" value = <?= $projectID ?>> 

  <section class="vh project-details-tab-box project-details-page">
      <div class="main-bg-video fixed">
          <video autoplay="" playsinline="" muted="" loop="" class="interest-video">
              <source src="<?= url(); ?>/assets/video/listing.mp4" type="video/mp4">
              Your browser does not support HTML5 video.
          </video>
      </div>
        
<div class="container">
                        <!-- breadcrumb -->
                        <?php include_once('breadcrumb.php'); ?>
    </div>
      <h1><?= !empty($projectName) ? $projectName : '' ?></h1>
      <h3><?= !empty($community) ? $community : '' ?></h3>
      <div class="container">
          <div class="row">
              <div class="col-lg-12">
                  <!-- Project Tabs -->
                  <?php if (!empty($availableTabs)): ?>
                    <div class="project-tabs">
                        <?php foreach ($availableTabs as $tabIndex => $tabDetails):
                            $tabLabel = htmlspecialchars($tabDetails['label'] ?? '', ENT_QUOTES, 'UTF-8');
                            $tabId = htmlspecialchars($tabDetails['tab'] ?? '', ENT_QUOTES, 'UTF-8');
                            $tabClasses = 'project-tab-link' . ($tabIndex === 0 ? ' active' : '');
                        ?>
                        <div class="<?= $tabClasses ?>" data-project-tab="<?= $tabId ?>"><?= $tabLabel ?></div>
                        <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (empty($availableTabs)): ?>
                    <!-- Tab navigation is driven entirely by project-detail-data.json entries. -->
                  <?php endif; ?>

              </div>
              <div class="col-lg-8">
                  <!-- Project Tab Content Container -->
                  <?php if (!empty($availableTabs)): ?>
                    <div class="project-tab-container">
                        <?php foreach ($availableTabs as $tabIndex => $tabDetails):
                            $tabId = htmlspecialchars($tabDetails['tab'] ?? '', ENT_QUOTES, 'UTF-8');
                            $tabContent = $tabDetails['content'] ?? '';
                            if (empty($tabId) || $tabContent === '') {
                                continue;
                            }
                            $contentClasses = 'project-tab-content' . ($tabIndex === 0 ? ' active' : '');
                        ?>
                        <div id="<?= $tabId ?>" class="<?= $contentClasses ?>">
                            <?= $tabContent ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (empty($availableTabs)): ?>
                    <!-- Tab content is driven entirely by project-detail-data.json entries. -->
                  <?php endif; ?>

                  
              </div> 
              <div class="col-lg-4">
                <div class="project-details-overview">
                  <h2><?= !empty($unitTypeName) ? $unitTypeName : '' ?> | <small><?= !empty($projectName) ? $projectName : '' ?></small></h2>
                  <?php if (!empty($soldOut) && $soldOut === true): ?>
                    <div class="sold-out-badge" role="status" aria-live="polite">Sold Out</div>
                  <?php endif; ?>
                  <ul>
                    <li>
                      <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 18" fill="none">
                        <path d="M3.48666 8.82277L0.271973 10.0631L3.33057 17.969L6.54525 16.7245L6.28369 16.0495L6.9376 15.8006L13.6876 17.4375C14.3618 17.6022 15.0704 17.555 15.7168 17.3025L22.5301 14.6657C23.0017 14.4806 23.3804 14.1156 23.583 13.6512C23.7855 13.1868 23.7953 12.6609 23.6101 12.1893C23.4249 11.7177 23.06 11.339 22.5956 11.1364C22.1312 10.9339 21.6053 10.9242 21.1337 11.1093L17.4845 12.5142L17.4549 12.4256C17.3308 12.0605 17.1283 11.7271 16.8615 11.4487C16.5948 11.1703 16.2703 10.9536 15.9109 10.814L9.16088 8.23636C8.49982 7.98111 7.76741 7.98111 7.10635 8.23636L3.75244 9.53574L3.48666 8.82277ZM6.86166 14.8387L5.94619 15.1931L4.08994 10.3964L7.46494 9.09699C7.91325 8.92599 8.40882 8.92599 8.85713 9.09699L15.6071 11.6789C15.8405 11.7677 16.0512 11.9073 16.2241 12.0875C16.3971 12.2677 16.5278 12.484 16.607 12.7209L16.6492 12.8475L16.4635 12.9192C15.9056 13.1377 15.2882 13.1511 14.7212 12.9571L10.3126 11.4595L10.0173 12.3328L14.4174 13.8262C15.1895 14.086 16.0281 14.0681 16.7884 13.7756L16.9318 13.7165L17.3832 13.5435L21.467 11.9531C21.5887 11.8986 21.7202 11.8695 21.8535 11.8674C21.9869 11.8654 22.1192 11.8905 22.2425 11.9412C22.3659 11.992 22.4776 12.0673 22.5709 12.1625C22.6642 12.2578 22.7371 12.3711 22.7852 12.4954C22.8334 12.6198 22.8557 12.7526 22.8509 12.8859C22.8461 13.0192 22.8142 13.15 22.7571 13.2706C22.7001 13.3911 22.6192 13.4988 22.5192 13.5871C22.4193 13.6753 22.3024 13.7423 22.1757 13.784L15.3751 16.4292C14.9027 16.6105 14.3864 16.6444 13.8943 16.5262L6.86166 14.8387Z" fill="white"/>
                        <path d="M16.3286 0.0310078C15.4389 0.0301733 14.569 0.293219 13.8289 0.786869C13.0888 1.28052 12.5117 1.98259 12.1707 2.80427C11.8297 3.62595 11.74 4.53032 11.913 5.40296C12.0861 6.27561 12.5141 7.07733 13.1429 7.70669C13.7716 8.33606 14.5729 8.76479 15.4454 8.93866C16.3179 9.11253 17.2224 9.02371 18.0444 8.68346C18.8664 8.3432 19.569 7.76679 20.0633 7.02714C20.5577 6.28749 20.8215 5.41783 20.8215 4.5282C20.8204 3.33654 20.3468 2.19395 19.5046 1.35093C18.6624 0.507902 17.5202 0.033241 16.3286 0.0310078ZM16.3286 8.4432C15.5541 8.44403 14.7967 8.21513 14.1524 7.78545C13.508 7.35577 13.0055 6.74463 12.7086 6.02933C12.4116 5.31403 12.3335 4.52672 12.484 3.76701C12.6346 3.00729 13.0072 2.30931 13.5545 1.76137C14.1019 1.21342 14.7995 0.840136 15.559 0.688733C16.3186 0.537329 17.1059 0.614612 17.8216 0.910805C18.5372 1.207 19.1489 1.70879 19.5792 2.3527C20.0096 2.99661 20.2393 3.7537 20.2393 4.5282C20.2382 5.56545 19.826 6.55996 19.0929 7.2938C18.3599 8.02764 17.3658 8.44096 16.3286 8.4432Z" fill="white"/>
                        <path d="M16.5057 3.73925V2.05175C16.6795 2.09434 16.8449 2.16563 16.9951 2.26269C17.1552 2.37425 17.2902 2.51793 17.3917 2.68456C17.5086 2.87495 17.5981 3.08089 17.6575 3.29628H17.8051V2.1994C17.4078 1.96609 16.9644 1.82212 16.5057 1.77753V1.60034H16.1767V1.77753C15.7549 1.80357 15.354 1.96989 15.0376 2.25003C14.9182 2.36207 14.8229 2.49731 14.7576 2.64748C14.6923 2.79765 14.6584 2.95957 14.6579 3.12331C14.6476 3.42266 14.7534 3.71442 14.9532 3.93753C15.3186 4.29499 15.7284 4.60408 16.1725 4.85722V6.73456C15.9569 6.72139 15.7461 6.66546 15.5523 6.57003C15.3787 6.4819 15.2327 6.34761 15.1304 6.1819C15.0079 5.97434 14.9262 5.7452 14.89 5.5069H14.7296V6.73456C14.9608 6.82535 15.1993 6.89593 15.4426 6.9455C15.6791 6.98959 15.9192 7.01219 16.1598 7.013V7.43487H16.4889V6.9919C16.8987 6.97567 17.2887 6.8107 17.5857 6.52784C17.7156 6.39809 17.8177 6.2433 17.8859 6.07287C17.954 5.90245 17.9868 5.71994 17.9823 5.53644C17.9888 5.19538 17.8703 4.86373 17.649 4.60409C17.3039 4.27115 16.92 3.9808 16.5057 3.73925ZM16.1767 3.51565C15.9543 3.38772 15.7559 3.22213 15.5903 3.02628C15.539 2.96365 15.5017 2.89083 15.4808 2.81266C15.46 2.73449 15.456 2.65277 15.4691 2.57293C15.4823 2.4931 15.5123 2.41699 15.5572 2.34967C15.6021 2.28236 15.6608 2.22538 15.7295 2.18253C15.8616 2.0914 16.0164 2.03881 16.1767 2.03065V3.51565ZM16.9867 6.46878C16.8599 6.607 16.6907 6.69903 16.5057 6.73034V5.08503C16.6964 5.2143 16.8645 5.37391 17.0035 5.55753C17.0905 5.69213 17.1373 5.8487 17.1385 6.00894C17.1442 6.1754 17.0904 6.33844 16.9867 6.46878Z" fill="white"/>
                      </svg> <span><?= !empty($startingPrice) ? formatPriceDisplay($startingPrice, $isUK) : '' ?></span>
                    </li>
                    <li>
                      <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 19 18" fill="none">
                      <g clip-path="url(#clip0_1431_16570)">
                      <path d="M17.6562 0H1.34375C1.12005 0.000260524 0.905594 0.089239 0.747416 0.247416C0.589239 0.405594 0.500261 0.620054 0.5 0.84375L0.5 17.1562C0.500261 17.3799 0.589239 17.5944 0.747416 17.7526C0.905594 17.9108 1.12005 17.9997 1.34375 18H17.6562C17.8799 17.9997 18.0944 17.9108 18.2526 17.7526C18.4108 17.5944 18.4997 17.3799 18.5 17.1562V0.84375C18.4997 0.620054 18.4108 0.405594 18.2526 0.247416C18.0944 0.089239 17.8799 0.000260524 17.6562 0ZM17.9375 17.1562C17.9374 17.2308 17.9078 17.3023 17.855 17.355C17.8023 17.4078 17.7308 17.4374 17.6562 17.4375H1.34375C1.26918 17.4374 1.19769 17.4078 1.14496 17.355C1.09223 17.3023 1.06257 17.2308 1.0625 17.1562V0.84375C1.06257 0.769181 1.09223 0.697687 1.14496 0.644958C1.19769 0.59223 1.26918 0.562574 1.34375 0.5625H17.6562C17.7308 0.562574 17.8023 0.59223 17.855 0.644958C17.9078 0.697687 17.9374 0.769181 17.9375 0.84375V17.1562Z" fill="white"/>
                      <path d="M12.0312 5.90625H6.96875C6.81962 5.90644 6.67666 5.96576 6.57121 6.07121C6.46576 6.17666 6.40644 6.31962 6.40625 6.46875V11.5312C6.4064 11.6804 6.46571 11.8234 6.57117 11.9288C6.67662 12.0343 6.81961 12.0936 6.96875 12.0938H12.0312C12.1804 12.0936 12.3234 12.0343 12.4288 11.9288C12.5343 11.8234 12.5936 11.6804 12.5938 11.5312V6.46875C12.5936 6.31962 12.5342 6.17666 12.4288 6.07121C12.3233 5.96576 12.1804 5.90644 12.0312 5.90625ZM6.96875 11.5312V6.46875H12.0312L12.0315 11.5312H6.96875Z" fill="white"/>
                      <path d="M11.1877 7.03117H7.81266C7.73807 7.03117 7.66654 7.0608 7.61379 7.11355C7.56105 7.16629 7.53141 7.23783 7.53141 7.31242V10.6874C7.53141 10.762 7.56105 10.8336 7.61379 10.8863C7.66654 10.939 7.73807 10.9687 7.81266 10.9687H11.1877C11.2623 10.9687 11.3338 10.939 11.3865 10.8863C11.4393 10.8336 11.4689 10.762 11.4689 10.6874V7.31242C11.4689 7.23783 11.4393 7.16629 11.3865 7.11355C11.3338 7.0608 11.2623 7.03117 11.1877 7.03117ZM10.9064 10.4062H8.09391V7.59367H10.9064V10.4062ZM3.6572 2.68909L3.97754 2.7182C4.01433 2.72154 4.05141 2.71761 4.08668 2.70662C4.12194 2.69563 4.1547 2.6778 4.18307 2.65415C4.21145 2.6305 4.23489 2.6015 4.25205 2.56879C4.26922 2.53608 4.27978 2.50032 4.28312 2.46353C4.28646 2.42674 4.28252 2.38966 4.27154 2.35439C4.26055 2.31913 4.24272 2.28637 4.21907 2.258C4.19542 2.22962 4.16642 2.20618 4.13371 2.18902C4.101 2.17185 4.06523 2.16129 4.02845 2.15795L2.93466 2.05853C2.8936 2.05479 2.85222 2.06013 2.81345 2.07417C2.77467 2.0882 2.73946 2.11059 2.71031 2.13975C2.68115 2.16891 2.65876 2.20412 2.64472 2.24289C2.63069 2.28166 2.62535 2.32304 2.62909 2.36411L2.72851 3.45789C2.73482 3.52777 2.76703 3.59277 2.81881 3.64012C2.87058 3.68747 2.93819 3.71376 3.00835 3.71383C3.01679 3.71383 3.02537 3.71341 3.03409 3.71242C3.10833 3.70564 3.17685 3.66966 3.22458 3.61239C3.2723 3.55511 3.29534 3.48123 3.28862 3.40698L3.25965 3.08678L5.3454 5.17253C5.39844 5.22376 5.46949 5.25211 5.54323 5.25147C5.61697 5.25083 5.68751 5.22125 5.73966 5.1691C5.79181 5.11696 5.82139 5.04642 5.82203 4.97267C5.82267 4.89893 5.79432 4.82789 5.74309 4.77484L3.6572 2.68909ZM5.3454 12.8273L3.25965 14.9131L3.28876 14.5927C3.29547 14.5184 3.2724 14.4445 3.22461 14.3872C3.17682 14.3299 3.10824 14.294 3.03395 14.2873C2.95965 14.2806 2.88573 14.3036 2.82845 14.3514C2.77117 14.3992 2.73522 14.4678 2.72851 14.5421L2.62909 15.6357C2.62555 15.6747 2.63016 15.7139 2.64263 15.7509C2.6551 15.788 2.67516 15.822 2.70151 15.8509C2.72787 15.8797 2.75995 15.9028 2.79571 15.9185C2.83147 15.9343 2.87013 15.9424 2.90921 15.9424C2.91765 15.9424 2.92609 15.942 2.93466 15.9413L4.02845 15.8419C4.10274 15.8351 4.17131 15.7992 4.21907 15.7418C4.26683 15.6845 4.28987 15.6106 4.28312 15.5363C4.27637 15.462 4.24038 15.3934 4.18307 15.3457C4.12577 15.2979 4.05183 15.2749 3.97754 15.2816L3.65734 15.3107L5.74309 13.225C5.79432 13.172 5.82267 13.1009 5.82203 13.0272C5.82139 12.9534 5.79181 12.8829 5.73966 12.8307C5.68751 12.7786 5.61697 12.749 5.54323 12.7484C5.46949 12.7477 5.39844 12.7761 5.3454 12.8273ZM16.0657 2.05853L14.9719 2.15795C14.9351 2.16129 14.8993 2.17185 14.8666 2.18902C14.8339 2.20618 14.8049 2.22962 14.7813 2.258C14.7576 2.28637 14.7398 2.31913 14.7288 2.35439C14.7178 2.38966 14.7139 2.42674 14.7172 2.46353C14.7206 2.50032 14.7311 2.53608 14.7483 2.56879C14.7654 2.6015 14.7889 2.6305 14.8173 2.65415C14.8456 2.6778 14.8784 2.69563 14.9137 2.70662C14.9489 2.71761 14.986 2.72154 15.0228 2.7182L15.343 2.68909L13.2572 4.77484C13.2304 4.80079 13.209 4.83182 13.1942 4.86614C13.1795 4.90045 13.1717 4.93735 13.1714 4.9747C13.1711 5.01204 13.1782 5.04908 13.1923 5.08364C13.2065 5.11821 13.2273 5.14961 13.2538 5.17602C13.2802 5.20242 13.3116 5.22331 13.3461 5.23745C13.3807 5.25159 13.4177 5.25871 13.4551 5.25838C13.4924 5.25806 13.5293 5.2503 13.5636 5.23556C13.598 5.22082 13.629 5.19939 13.6549 5.17253L15.7407 3.08678L15.7116 3.40698C15.7048 3.48126 15.7278 3.55517 15.7756 3.61248C15.8233 3.66978 15.8918 3.70578 15.9661 3.71256C15.9748 3.71341 15.9835 3.71369 15.9921 3.71369C16.0622 3.71359 16.1298 3.6873 16.1815 3.63998C16.2333 3.59266 16.2655 3.52772 16.2718 3.45789L16.3712 2.36411C16.375 2.32304 16.3696 2.28166 16.3556 2.24289C16.3416 2.20412 16.3192 2.16891 16.29 2.13975C16.2609 2.11059 16.2257 2.0882 16.1869 2.07417C16.1481 2.06013 16.1067 2.05479 16.0657 2.05853ZM15.9662 14.2873C15.892 14.2941 15.8235 14.33 15.7758 14.3873C15.728 14.4446 15.705 14.5185 15.7117 14.5927L15.7407 14.9131L13.6549 12.8273C13.6019 12.7761 13.5308 12.7477 13.4571 12.7484C13.3834 12.749 13.3128 12.7786 13.2607 12.8307C13.2085 12.8829 13.1789 12.9534 13.1783 13.0272C13.1777 13.1009 13.206 13.172 13.2572 13.225L15.343 15.3107L15.0228 15.2816C14.9485 15.2749 14.8746 15.2979 14.8173 15.3457C14.7599 15.3934 14.724 15.462 14.7172 15.5363C14.7105 15.6106 14.7335 15.6845 14.7813 15.7418C14.829 15.7992 14.8976 15.8351 14.9719 15.8419L16.0657 15.9413C16.0741 15.942 16.0827 15.9424 16.0911 15.9424C16.1302 15.9424 16.1688 15.9343 16.2046 15.9185C16.2403 15.9028 16.2724 15.8797 16.2988 15.8509C16.3251 15.8221 16.3452 15.788 16.3576 15.751C16.3701 15.714 16.3748 15.6748 16.3712 15.6359L16.2718 14.542C16.2647 14.4678 16.2286 14.3995 16.1714 14.3518C16.1142 14.3041 16.0405 14.2809 15.9662 14.2873Z" fill="white"/>
                      </g>
                      </svg> <span>Starting <?= !empty($startingArea) ? round($startingArea) : '' ?> SQ FT *</span>
                    </li>
                  </ul>
                  <?php
                    $defaultProjectDescription = 'Binghatti projects embody a bold vision of modern architecture and lifestyle. Characterized by distinctive geometric designs and refined interiors, each development offers a unique blend of innovation, elegance, and functionality. With a focus on delivering premium urban living experiences, Binghatti properties cater to residents and investors who seek design-led spaces in prime Dubai locations.';
                    $descriptionText = trim($projectDescription ?? '');
                    if ($descriptionText === '') {
                        $descriptionText = $defaultProjectDescription;
                    }
                  ?>
                  <p><?= $renderProjectDescription($descriptionText) ?></p>
                  <?php
                  $checkoutUrl = url() . '/checkout?' . http_build_query([
                      'ProjectID' => $projectID ?? '',
                      'ProjectName' => $projectName ?? '',
                      'UnitTypeID' => $unitTypeID ?? '',
                      'UnitTypeName' => $unitTypeName ?? '',
                      'StartingArea' => $startingArea ?? '',
                      'StartingPrice' => $startingPrice ?? '',
                      'GoogleLocationURL' => $googleLocationURL ?? '',
                      'Community' => $community ?? '',
                      'ProjectCategory' => $projectCategory ?? '',
                      'HandoverDate' => $handoverDate ?? '',
                      'UnitCount' => $unitCount ?? '',
                      'NumberOfBedrooms' => $numberOfBedrooms ?? ''
                  ]);
                  ?>
                  <a class="custom-btn js-book-now" href="#" data-redirect-url="<?= htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') ?>">BOOK NOW</a>
                </div>
              </div>
          </div> <!-- row -->
      </div> <!-- container -->
  </section>

  
  <?php require_once ('footer.php'); ?>


  <script src="<?= url(); ?>/assets/js/jquery.min.js"></script>
  <script src="https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js"></script>
  <script src="<?= url(); ?>/assets/js/flatpickr.min.js"></script>
  <script src="https://www.google.com/recaptcha/api.js?render=6LcGEQgqAAAAAP_hHyvKi8kU6oxxyRLXg69BdrTz"></script>
  <script src="<?= url(); ?>/assets/js/main.js"></script>

  <script>

    /*===================================
    =            Project Tab            =
    ===================================*/

    $(document).ready(function () {
        function updateTabHeight() {
            var activeTabHeight = $(".project-tab-content.active").outerHeight();
            $(".project-tab-container").css("height", activeTabHeight + "px");
        }

        // Set initial height on page load
        updateTabHeight();

        $(".project-tab-link").click(function () {
            var tabID = $(this).attr("data-project-tab");

            // Remove active class from all tabs and add to clicked tab
            $(".project-tab-link").removeClass("active");
            $(this).addClass("active");

            // Slide out current active tab to the right
            $(".project-tab-content.active").css("transform", "translateX(100%)").css("opacity", "0");

            // Wait for animation, then hide it
            setTimeout(function () {
                $(".project-tab-content").removeClass("active").css("transform", "translateX(100%)");

                // Slide in new tab from the left
                $("#" + tabID).addClass("active").css("transform", "translateX(0)").css("opacity", "1");

                // Adjust container height after new content is visible
                setTimeout(updateTabHeight, 300);
            }, 500);
        });
    });
    
    /*=====  End of Project Tab  ======*/
    
    /*==================================================
    =            Project Details Tab Slider            =
    ==================================================*/
    
    (function initProjectDetailsSliders() {
        const setupFlickitySliders = () => {
            if (typeof Flickity === 'undefined') {
                return;
            }

            document.querySelectorAll('.project-details-slider-tabs').forEach((section) => {
                const slider = section.querySelector('.project-details-tab-slider');
                if (!slider || slider.dataset.flickityInit === 'true') {
                    return;
                }

                slider.dataset.flickityInit = 'true';

                const flickityInstance = new Flickity(slider, {
                    cellAlign: 'left',
                    contain: true,
                    pageDots: false,
                    prevNextButtons: false,
                    draggable: true,
                    freeScroll: false,
                    pageDots: false,
                    wrapAround: false,
                    groupCells: true,
                    adaptiveHeight: false,
                    lazyLoad: 2,
                    imagesLoaded: true,
                    percentPosition: true
                });

                const updateActiveSlide = () => {
                    slider.querySelectorAll('.project-details-tab-slider-content').forEach((cell) => {
                        cell.classList.remove('project-details-tab-slide-active');
                    });

                    const cells = slider.querySelectorAll('.project-details-tab-slider-content');
                    const activeCell = cells[flickityInstance.selectedIndex];
                    if (activeCell) {
                        activeCell.classList.add('project-details-tab-slide-active');
                    }
                };

                flickityInstance.on('ready', updateActiveSlide);
                flickityInstance.on('select', updateActiveSlide);
                updateActiveSlide();

                const prevArrow = section.querySelector('.project-details-tab-slider-arrow-left');
                const nextArrow = section.querySelector('.project-details-tab-slider-arrow-right');

                if (prevArrow) {
                    prevArrow.addEventListener('click', () => flickityInstance.previous());
                }

                if (nextArrow) {
                    nextArrow.addEventListener('click', () => flickityInstance.next());
                }
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupFlickitySliders);
        } else {
            setupFlickitySliders();
        }
    })();


    /*=====  End of Project Details Tab Slider  ======*/
    
    /*========================================
    =            Check Visibility            =
    ========================================*/

    function onVisible(element, callback) {
        let observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    callback();
                    observer.disconnect(); // Stop observing once the callback is triggered
                }
            });
        }, { threshold: 0.50 }); // Trigger when 75% of the element is visible

        document.querySelectorAll(element).forEach(el => observer.observe(el));
    }

    /*=====  End of Check Visibility  ======*/
    
    /*==================================
    =            Title Bold            =
    ==================================*/
    
    function styleTextElement(element) {
      if (!element || element.dataset.processed === "true") return;

      const originalText = element.textContent.trim();
      const words = originalText.split(" ");

      const styledHTML = words
        .map(word => word.toUpperCase() === "BINGHATTI"
          ? `<span style="font-weight: 100;">${word}</span>`
          : `<span style="font-weight: bold;">${word}</span>`)
        .join(" ");

      element.innerHTML = styledHTML;
      element.dataset.processed = "true";
    }

    function processAllHeadings() {
      const h1 = document.querySelector(".project-details-page h1");
      const h2small = document.querySelector(".project-details-tab-box h2 small");

      styleTextElement(h1);
      styleTextElement(h2small);
    }

    // Run on page load
    document.addEventListener("DOMContentLoaded", processAllHeadings);

    // MutationObserver to catch dynamic updates
    const observer = new MutationObserver(processAllHeadings);
    observer.observe(document.body, { childList: true, subtree: true });

    /*=====  End of Title Bold  ======*/

    function redirectBookNow(event) {
      event.preventDefault();
      const targetUrl = event.currentTarget.getAttribute("data-redirect-url");
      if (targetUrl) {
        window.location.assign(targetUrl);
      }
    }

    document.addEventListener("DOMContentLoaded", function () {
      document.querySelectorAll(".js-book-now").forEach(function (link) {
        link.addEventListener("click", redirectBookNow);
      });
    });
    
  </script>
</body>
</html>
