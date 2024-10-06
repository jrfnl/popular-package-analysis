<?php error_reporting(E_ALL);

/*
 * Popular packages: sorted by downloads over the last week, not overall downloads to ensure we demote formerly-popular packages.
 *
 * Downloading complete top 2000 takes approximately 1 hour to download.
 * Unzipping the complete top 2000 takes approximately 15 minutes.
 * Re-running without needing to download still takes nearly 20 minutes due to all the API calls.
 */

function getTopPackages($min, $max, $targetDir, $date) {
    $perPage = 50;
    $page = intdiv($min, $perPage);
    $id = $page * $perPage;
    $list_file = $targetDir . '/' . $date . '-top' . $min . '-' . $max . '-page%03d.txt';
    while (true) {
        $page++;
        $url = 'https://packagist.org/explore/popular.json?per_page=' . $perPage . '&page=' . $page;
        $json = json_decode(file_get_contents($url), true);
        file_put_contents(sprintf($list_file, $page), var_export($json, true));
        foreach ($json['packages'] as $package) {
            yield $id => $package['name'];
            $id++;
            if ($id >= $max) {
                return;
            }
        }
    }
}

function getKeyToLatestVersion($packages) {
    $highestVersion    = '0.0';
    $highestVersionKey = null;
    $devMasterKey      = null;
    $devLatestKey      = null;
    $devMainKey        = null;
    foreach ($packages as $key => $info) {
        // Keep track of some typical "default" branch names as a fall-back.
        if (strpos($info['version_normalized'], 'dev-') === 0) {
            if ($info['version_normalized'] === 'dev-main') {
                $devMainKey = $key;
                continue;
            }

            if ($info['version_normalized'] === 'dev-master') {
                $devMasterKey = $key;
                continue;
            }

            if ($info['version_normalized'] === 'dev-latest') {
                $devLatestKey = $key;
                continue;
            }
        }

        if (version_compare($info['version_normalized'], $highestVersion, '>')) {
            $highestVersion    = $info['version_normalized'];
            $highestVersionKey = $key;
        }
    }

    if ($highestVersionKey !== null) {
        return $highestVersionKey;
    }

    // No tagged version found. Use whichever version has the "dist" key (if available).
    if ($devLatestKey !== null && isset($packages[$devLatestKey]['dist'])) {
        return $devLatestKey;
    }
    if ($devMainKey !== null && isset($packages[$devMainKey]['dist'])) {
        return $devMainKey;
    }
    if ($devMasterKey !== null && isset($packages[$devMasterKey]['dist'])) {
        return $devMasterKey;
    }

    return $highestVersionKey;
}

if ($argc < 3) {
    echo "Usage: download.php min-package max-package", PHP_EOL;
    return;
}

$minPackage = $argv[1];
$maxPackage = $argv[2];

// Create directory in which to place the final files.
$date = date('Ymd');
$targetDir = dirname(__DIR__) . '/'. $date . '_top' . $minPackage . '-' . $maxPackage;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}
copy(__DIR__ . '/extract.sh', $targetDir . '/extract.sh');

foreach (getTopPackages($minPackage, $maxPackage, $targetDir, $date) as $i => $packageName) {
    if ($i < $minPackage || $i > $maxPackage) {
        continue;
    }

    echo PHP_EOL, "[$i] $packageName", PHP_EOL;
    $packageName = strtolower($packageName);
    $url = 'https://repo.packagist.org/p2/' . $packageName . '.json';
    $json = json_decode(file_get_contents($url), true);

    if (empty($json['packages'][$packageName])) {
        $url = 'https://repo.packagist.org/p2/' . $packageName . '~dev.json';
        $json = json_decode(file_get_contents($url), true);
    }

    $keyToLatestVersion = getKeyToLatestVersion($json['packages'][$packageName]);
    if ($keyToLatestVersion === null || isset($json['packages'][$packageName][$keyToLatestVersion]) === false) {
        echo "Skipping as no tagged releases and no default branch found", PHP_EOL;
        continue;
    }

    $latestVersion = $json['packages'][$packageName][$keyToLatestVersion];
    if ($latestVersion['dist'] === null) {
        echo "Skipping due to missing dist", PHP_EOL;
        continue;
    }

    $dist = $latestVersion['dist']['url'];
    $zipball = __DIR__ . '/zipballs/' . $packageName . '--' . $latestVersion['version']. '.zip';
    if (strpos($latestVersion['version'], 'dev-') === 0 && file_exists($zipball)) {
        echo "Removing previously downloaded {$latestVersion['version']} zip...", PHP_EOL;
        unlink($zipball);
    }

    // Download to archive - this will prevent having to re-download every single time.
    if (!file_exists($zipball)) {
        echo "Downloading {$latestVersion['version']}...", PHP_EOL;
        $dir = dirname($zipball);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        exec("wget $dist -O $zipball", $execOutput, $execRetval);
        if ($execRetval !== 0) {
            echo "wget failed: $execOutput", PHP_EOL;
            break;
        }
    } else {
        echo "File already exists, previously downloaded", PHP_EOL;
    }

    // Copy only the current top 2000.
    $to  = str_replace(__DIR__, $targetDir, $zipball);
    $dir = dirname($to);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    copy($zipball, $to);
}
