<?php
/**
 *
 * PHP scrapper for getting HTML data from any site with built-in translation function using ChatGPT API
 * TODO: refactor GuzzleHttp to Symfony/Panther or ony other Headless browser (Goutte)
 *
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use DiDom\Document;
use DiDom\Query;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('GPT_TOKEN', $_ENV['GPT_API']);

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
const TITLE_SELECTOR = "h1";
const PRICE_XPATH = "//div[@class='sc-152o12i-11 eUXUzN priceTag ves8oa-16 bCciqG']";
const BEDROOM_XPATH = "//div[@class='sc-152o12i-12 jcFPVq informationSpan']/div[1]";
const FLOOR_XPATH = "//div[@class='sc-152o12i-12 jcFPVq informationSpan']/div[@class='floor']";
const IMAGES_XPATH = "//div[@class='image-gallery-slides']//img/@src";
const DESCRIPTION_XPATH = "//div[@class='ves8oa-21 bGEqDy']";
const AREA_XPATH = "//div[@class='row']/div[@class='col-xs sc-1qj7qf1-2 jXJSll'][2]/div[@class='ogfj7g-12 eMSrgc']/div[@class='sc-152o12i-12 jcFPVq informationSpan']/div[2]";

/**
 * Parse the Entry page to collect the list of the links to visit
 * @param string $url
 * @param string $xpath
 * @param string $domain
 * @return array
 * */
function collectLinksList(string $url, string $xpath, string $domain): Array
{
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
 * @return array
 *
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function scrapeFields(array $links): Array
{
    try {
        $raw_array = [];

        foreach ($links as $link) {
            global $client;
            $response = $client->get($link)->getBody()->getContents();
            $tree = new Document($response);
            $title = $tree->find(TITLE_SELECTOR);
            $price = $tree->find(PRICE_XPATH, Query::TYPE_XPATH);
            $bedrooms = $tree->find(BEDROOM_XPATH, Query::TYPE_XPATH);
            $area = $tree->find(AREA_XPATH, Query::TYPE_XPATH);
            $floor = $tree->find(FLOOR_XPATH, Query::TYPE_XPATH);
            $images = $tree->find(IMAGES_XPATH, Query::TYPE_XPATH);
            $description = $tree->find(DESCRIPTION_XPATH, Query::TYPE_XPATH);
            array_push($raw_array, [$title, $price, $bedrooms, $area, $floor, $images, $description]);
        }
        return $raw_array;
    }  catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}

/**
 * Build Array of all scrapped data ready to export to CSV
 * @param array $raw_array
 * @return array
 * */
function buildTableArray(array $raw_array): Array
{
    try {


        $data_array = [['Title', 'Price', 'Bedrooms', 'Room area', 'Floor', 'Images']];
        foreach ($raw_array as $row) {
            $title = $row[0][0] ? $row[0][0]->text() : '';
            $price = $row[1][0]? preg_replace("/[^0-9]/", "", $row[1][0]->text()) : '';
            $bedrooms = $row[2][0]? preg_replace("/[^0-9]/", "", $row[2][0]->text()) : '';
            $room_area = $row[3][0]? preg_replace("/(?=\.).*/", "", $row[3][0]->text()) : '';
            $floor = $row[4]? preg_replace("/[^0-9]/", "", $row[4][0]->text()) : '';
            $images = $row[5]? implode(', ', $row[5]): '';
            $description = $row[6]? $row[6][0]->text(): '';
            /*$client = OpenAI::client(GPT_TOKEN);
            $translated_description = $client->completions()->create([
                'model' => 'text-davinci-003',
                'prompt' => 'Переведи на русский: "' . $description . '"',
                'max_tokens' => 200
            ]);*/
            array_push($data_array, [
                $title,
                $price,
                $bedrooms,
                $room_area,
                $floor,
                $images,
                $description
            ]);
        }
        return $data_array;
    } catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}

/**
 * Initializiation function
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function initScraping(): Array
{
    $links_array = collectLinksList(ENTER_POINT, SOURCE_LINK_XPATH, SOURCE_DOMAIN);
    $fields = scrapeFields($links_array);
    return buildTableArray($fields);
}

function drawTable(array $array) {
    if(!is_array($array)) {
        return;
    }
    $markup = "<table>";
    foreach ($array as $row) {
        $markup .= '<tr>';
        foreach ($row as $cell) {
            $markup .= '<td>' . $cell . '</td>';
        }
        $markup .= '</tr>';
    }
    $markup .= '</table>';

    echo $markup;
}

$_start = microtime(true);

try {
    $result = initScraping();
    drawTable($result);
    //print_r($result);
} catch (\GuzzleHttp\Exception\GuzzleException $e) {
}

//printf('scraped %d results in %.2f seconds', count($result) - 1, microtime(true) - $_start);
