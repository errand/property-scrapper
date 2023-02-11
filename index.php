<?php

require './vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use DiDom\Document;
use DiDom\Query;

$client = new Client([
    'connect_timeout' => 10,
    'timeout'         => 10.00,
    'http_errors'     => true,
    'headers' => [
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.104 Safari/537.36',
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',]
]);

const ENTER_POINT = 'https://propertyhub.in.th/en/condo-for-rent/project-sukhumvit-living-town';
const SOURCE_DOMAIN = 'https://propertyhub.in.th';
const SOURCE_LINK_XPATH = "//div[@class='sc-152o12i-0 iWSTG i5hg7z-1 dnNQUL']//a[@class='sc-152o12i-1 eVFiiC']/@href";

/**
 * Parse the Entry page to collect the list of the links to visit
 * @param string $url
 * @param string $xpath
 * @param string $domain
 * @return array
 * */
function collectLinksList(string $url, string $xpath, string $domain): Array {
    $document = new Document($url, true);
    $links_array = [];

    try {
        $row_links = $document->find($xpath, Query::TYPE_XPATH);
        foreach ($row_links as $link) {
            array_push($links_array, $domain . $link);
        }
        return $links_array;

    } catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}

/**
 * Parse each Page from the list and find given fields
 * @param array $links
 * @param string $xpath
 * @param string $domain
 * @return array
 *
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function scrapeFields(array $links): Array {

    try {
        $raw_array = [];

        foreach ($links as $link) {
            global $client;
            $response = $client->get($link)->getBody()->getContents();
            $tree = new Document($response);
            $title = $tree->find("h1");
            $price = $tree->find("//div[@class='sc-152o12i-11 eUXUzN priceTag ves8oa-16 bCciqG']", Query::TYPE_XPATH);
            $bedrooms = $tree->find("//div[@class='sc-152o12i-12 jcFPVq informationSpan']/div[1]", Query::TYPE_XPATH);
            array_push($raw_array, [$title, $price, $bedrooms]);
        }

        return $raw_array;
    }  catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}


function buildTableArray(array $raw_array): Array {
    try {
        $data_array = [['Title', 'Price', 'Bedrooms']];

        foreach ($raw_array as $row) {
            array_push($data_array, [
                $row[0][0]->text(),
                preg_replace("/[^0-9]/", "", $row[1][0]->text())
            ]);
        }
        return $data_array;
    } catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}

function initScraping(): Array {
    $links_array = collectLinksList(ENTER_POINT, SOURCE_LINK_XPATH, SOURCE_DOMAIN);
    $fields = scrapeFields($links_array);
    return buildTableArray($fields);
}


print '<pre>';
print_r(initScraping());
print '<pre>';

function scrape($urls, $callback, $errback)
{
    // create 10 Request objects:
    $requests = array_map(function ($url) {
        return new Request('GET', $url);
    }, $urls);
    global $client;
    $pool = new Pool($client, $requests, [
        'concurrency' => 5,
        'fulfilled' => $callback,
        'rejected' => $errback,
    ]);
    $pool->promise()->wait();
}

function logFailure($reason, $index)
{
    printf("failed: %s\n", $reason);
}

$_start = microtime(true);


//scrape($links_array);
//printf('scraped %d results in %.2f seconds', count($data_array), microtime(true) - $_start);
