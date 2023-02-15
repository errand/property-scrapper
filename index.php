<?php
/**
 *
 * PHP scrapper for getting HTML data from any site with built-in translation function using ChatGPT API
 * TODO: refactor GuzzleHttp to Symfony/Panther or ony other Headless browser (Goutte)
 *
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use DiDom\Document;
use DiDom\Query;
use Dotenv\Dotenv;
use Panda\Yandex\TranslateSdk;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('GPT_TOKEN', $_ENV['GPT_API']);
define('YANDEX_TOKEN', $_ENV['YANDEX_API']);
define('YANDEX_FOLDER_ID', $_ENV['YANDEX_FOLDER_ID']);

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

$data_array = [['Title', 'Price', 'Bedrooms', 'Room area', 'Floor', 'Images']];

// First we define our main scraping loop:
function scrape($urls, $callback)
{
    $requests = array_map(function ($url) {
        return new Request('GET', (string) $url);
    }, $urls);
    global $client;
    $pool = new Pool($client, $requests, [
        'concurrency' => 5,
        'fulfilled' => $callback,
    ]);
    $pool->promise()->wait();
}

/**
 * Parse the Entry page to collect the list of the links to visit
 * @param string $url
 * @param string $xpath
 * @param string $domain
 * @return void
 * */
function collectLinksList(string $url, string $xpath, string $domain): void
{
    $document = new Document($url, true);
    $links_array = [];

    try {
        $row_links = $document->find($xpath, Query::TYPE_XPATH);
        foreach ($row_links as $link) {
            array_push($links_array, $domain . $link);
        }

        scrape($links_array, 'scrapeField');


        global $data_array;
        drawTable($data_array);

    } catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
}

/**
 * Parse each Page from the list and find given fields
 * @param Response $response
 * @param $index
 * @return void
 *
 * @throws GuzzleException
 */
function scrapeField(Response $response, $index): void
{
    try {
        $raw_array = [];
        global $data_array;

        $result = $response->getBody()->getContents();
        $tree = new Document($result);
        $title = $tree->find(TITLE_SELECTOR);
        $price = $tree->find(PRICE_XPATH, Query::TYPE_XPATH);
        $bedrooms = $tree->find(BEDROOM_XPATH, Query::TYPE_XPATH);
        $area = $tree->find(AREA_XPATH, Query::TYPE_XPATH);
        $floor = $tree->find(FLOOR_XPATH, Query::TYPE_XPATH);
        $images = $tree->find(IMAGES_XPATH, Query::TYPE_XPATH);
        $description = $tree->find(DESCRIPTION_XPATH, Query::TYPE_XPATH);
        array_push($raw_array, [$title, $price, $bedrooms, $area, $floor, $images, $description]);

        //$openAIClient = OpenAI::client(GPT_TOKEN);
        $cloud = new TranslateSdk\Cloud(YANDEX_TOKEN, YANDEX_FOLDER_ID);
        foreach ($raw_array as $row) {

            $title = $row[0][0] ? $row[0][0]->text() : '';
            $translate_title = new TranslateSdk\Translate($title, 'ru');
            $response_title = json_decode($cloud->request($translate_title));
            if ($title) {
                $translated_title = $response_title->translations[0]->text;
            } else {
                $translated_title = '';
            }
            /*$translated_title = $openAIClient->completions()->create([
                'model' => 'text-davinci-003',
                'prompt' => 'Переведи на русский: "' . $title . '"',
                'max_tokens' => 500
            ]);*/
            $price = $row[1][0]? preg_replace("/[^0-9]/", "", $row[1][0]->text()) : '';
            $bedrooms = $row[2][0]? preg_replace("/[^0-9]/", "", $row[2][0]->text()) : '';
            $room_area = $row[3][0]? preg_replace("/(?=\.).*/", "", $row[3][0]->text()) : '';
            $floor = $row[4]? preg_replace("/[^0-9]/", "", $row[4][0]->text()) : '';
            $images = $row[5]? implode(', ', $row[5]): '';

            $description = $row[6]? $row[6][0]->text(): '';
            $translate_description = new TranslateSdk\Translate($description, 'ru');
            $response_description = json_decode($cloud->request($translate_description));
            if($description) {
                $translated_description = $response_description->translations[0]->text;
            } else {
                $translated_description = '';

            }

            /*$translated_description = $openAIClient->completions()->create([
                'model' => 'text-davinci-003',
                'prompt' => 'Переведи на русский: "' . $description . '"',
                'max_tokens' => 2000
            ]);*/

            array_push($data_array, [
                //$title,
                $translated_title,
                $price,
                $bedrooms,
                $room_area,
                $floor,
                $images,
                //$description,
                $translated_description
            ]);
        }

    }  catch (\DiDom\Exceptions\InvalidSelectorException $e) {
        print_r($e);
    }
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
    collectLinksList(ENTER_POINT, SOURCE_LINK_XPATH, SOURCE_DOMAIN);
    /*$cloud = new TranslateSdk\Cloud(YANDEX_TOKEN, YANDEX_FOLDER_ID);
    $translate_description = new TranslateSdk\Translate('', 'ru');
    print_r(json_decode($cloud->request($translate_description))->code);*/

} catch (GuzzleException $e) {
}

printf('scraped results in %.2f seconds', microtime(true) - $_start);
