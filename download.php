<?php error_reporting(E_ALL);

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

if ($argc < 3) {
    echo "Usage: download.php min-package max-package\n";
    return;
}

$minPackage = $argv[1];
$maxPackage = $argv[2];
foreach (getTopPackages($minPackage, $maxPackage) as $i => $packageName) {
    echo "[$i] $packageName\n";
    $packageName = strtolower($packageName);
    $url = 'https://packagist.org/packages/' . $packageName . '.json';
    $json = json_decode(file_get_contents($url), true);
    $versions = $json['package']['versions'];
    if (isset($versions['dev-master'])) {
        $version = 'dev-master';
    } else if (isset($versions['dev-main'])) {
        $version = 'dev-main';
    } else {
        // Pick latest version.
        $keys = array_keys($versions);
        $version = $keys[0];
    }

    $package = $versions[$version];
    if ($package['dist'] === null) {
        echo "Skipping due to missing dist\n";
        continue;
    }

    $dist = $package['dist']['url'];
    $zipball = __DIR__ . '/zipballs/' . $packageName . '.zip';
    if (!file_exists($zipball)) {
        echo "Downloading $version...\n";
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
