<?php error_reporting(E_ALL);

/*
 * Popular packages: sorted by downloads over the last week, not overall downloads to ensure we demote formerly-popular packages.
 *
 * Downloading complete top 2000 takes approximately 1 hour to download.
 * Unzipping the complete top 2000 takes approximately 15 minutes.
 * Re-running without needing to download still takes nearly 20 minutes due to all the API calls.
 */

function getTopPackages($min, $max) {
    $perPage = 50;
    $page = intdiv($min, $perPage);
    $id = $page * $perPage;
    $list_file = __DIR__ . '/zipballs/' . date('Ymd') . '-top' . $max . '-page%03d.txt';
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
    echo "Usage: download.php min-package max-package\n";
    return;
}

$minPackage = $argv[1];
$maxPackage = $argv[2];
foreach (getTopPackages($minPackage, $maxPackage) as $i => $packageName) {
    echo "[$i] $packageName\n";
    $packageName = strtolower($packageName);
    $url = 'https://repo.packagist.org/p2/' . $packageName . '.json';
    $json = json_decode(file_get_contents($url), true);

    if (empty($json['packages'][$packageName])) {
        $url = 'https://repo.packagist.org/p2/' . $packageName . '~dev.json';
        $json = json_decode(file_get_contents($url), true);
    }

    $keyToLatestVersion = getKeyToLatestVersion($json['packages'][$packageName]);
    if ($keyToLatestVersion === null || isset($json['packages'][$packageName][$keyToLatestVersion]) === false) {
        echo "Skipping as no tagged releases and no default branch found" . PHP_EOL;
        continue;
    }

    $latestVersion = $json['packages'][$packageName][$keyToLatestVersion];
    if ($latestVersion['dist'] === null) {
        echo "Skipping due to missing dist\n";
        continue;
    }

    $dist = $latestVersion['dist']['url'];
    $zipball = __DIR__ . '/zipballs/' . $packageName . '--' . $latestVersion['version']. '.zip';
    if (!file_exists($zipball)) {
        echo "Downloading {$latestVersion['version']}...\n";
        $dir = dirname($zipball);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        exec("wget $dist -O $zipball", $execOutput, $execRetval);
        if ($execRetval !== 0) {
            echo "wget failed: $execOutput\n";
            break;
        }
    }
}
