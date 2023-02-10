<?php

require './vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use DiDom\Document;
use DiDom\Query;
use Symfony\Component\DomCrawler\Crawler;

$client = new Client([
    'connect_timeout' => 10,
    'timeout'         => 10.00,
    'http_errors'     => true,
    'headers' => [
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.104 Safari/537.36',
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',]
]);

$enter_point = 'https://propertyhub.in.th/en/condo-for-rent/project-sukhumvit-living-town';
$data_array = ['Title', 'Price'];
$links_array = [];


$document = new Document($enter_point, true);

try {
    $row_links = $document->find("//div[@class='sc-152o12i-0 iWSTG i5hg7z-1 dnNQUL']//a[@class='sc-152o12i-1 eVFiiC']/@href", Query::TYPE_XPATH);
    foreach ($row_links as $link) {
        array_push($links_array, 'https://propertyhub.in.th' . $link);
    }
} catch (\DiDom\Exceptions\InvalidSelectorException $e) {
    print_r($e);
}
$raw_array = [];

foreach ($links_array as $link) {
    $client = new Client();
    $response = $client->get($link)->getBody()->getContents();
    $tree = new Document($response);
    $title = $tree->find("h1");
    $price = $tree->find("//div[@class='sc-152o12i-11 eUXUzN priceTag ves8oa-16 bCciqG']", Query::TYPE_XPATH);
    $price = $tree->find("//div[@class='sc-152o12i-11 eUXUzN priceTag ves8oa-16 bCciqG']", Query::TYPE_XPATH);
    array_push($raw_array, [$title, $price]);
}

foreach ($raw_array as $row) {
    array_push($data_array, $row[0][0]->text(), preg_replace("/[^0-9]/", "", $row[1][0]->text()));
    //print 'Price: ' . $row[1][0]->text() . ' -> ' . preg_replace("/[^0-9]/", "", $row[1][0]->text() ). '<br/><br/>';

}
print '<pre>';
print_r($data_array);
print '<pre>';

function scrape($urls, $error)
{
    // create 10 Request objects:
    $requests = array_map(function ($url) {
        return new Request('GET', $url);
    }, $urls);
    global $client;
    $pool = new Pool($client, $requests, [
        'concurrency' => 5,
        'rejected' => $error,
    ]);
    $pool->promise()->wait();
}

function parseProduct(Response $response, $index)
{
    $tree = new Document($response->getBody()->getContents());
    $result = [
        // we can use xpath selectors:
        'title' => $tree->find("//h1", Query::TYPE_XPATH)->text(),
        'price' => $tree->find("//div[@class='sc-152o12i-11 eUXUzN priceTag ves8oa-16 bCciqG']", Query::TYPE_XPATH)->text(),
    ];
    global $data_array;
    array_push($data_array, $result);
}


function logFailure($reason, $index)
{
    printf("failed: %s\n", $reason);
}

$_start = microtime(true);
//scrape($links_array, 'logFailure');
printf('scraped %d results in %.2f seconds', count($data_array), microtime(true) - $_start);
echo '\n';
echo json_encode($data_array, JSON_PRETTY_PRINT);
