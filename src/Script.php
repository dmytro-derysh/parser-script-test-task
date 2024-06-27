<?php

require_once "../vendor/autoload.php";

use Exceptions\CsrfTokenNotExtractedException;
use Exceptions\SearchUuidNotExtractedException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;

try {
    $searchWord = $argv[1] ?? throw new InvalidArgumentException('Please provide a search word.');
    $script = new Script($searchWord);
    /**
     * @var \GuzzleHttp\Cookie\CookieJar $cookieJar
     * @var string $csrf
     */
    [$cookieJar, $csrf] = $script->fetchIndex();
    /**
     * @var \GuzzleHttp\Cookie\CookieJar $cookieJar
     * @var string $searchUuid
     */
    [$cookieJar, $searchUuid] = $script->doSearch($cookieJar, $csrf);
    $results = $script->collectResults($cookieJar, $searchUuid);

    file_put_contents('../results.json', json_encode($results, JSON_PRETTY_PRINT));

    $resultsCount = count($results);
    echo "Results: $resultsCount" . PHP_EOL;
    print_r($results);
} catch (\Exception $exception) {
    echo $exception->getMessage();

    if ($exception instanceof InvalidArgumentException) {
        exit(Script::INVALID);
    } else {
        exit(Script::FAILURE);
    }
}

exit(Script::SUCCESS);

class Script
{
    // see https://tldp.org/LDP/abs/html/exitcodes.html
    public const SUCCESS = 0;

    public const FAILURE = 1;

    public const INVALID = 2;

    protected const BASE_URL = 'https://search.ipaustralia.gov.au';

    protected const ADVANCED_SEARCH_INDEX_URL = 'https://search.ipaustralia.gov.au/trademarks/search/advanced';

    protected const ADVANCED_SEARCH_DO_SEARCH_URL = 'https://search.ipaustralia.gov.au/trademarks/search/doSearch';

    protected const ADVANCED_SEARCH_RESULTS_URL = 'https://search.ipaustralia.gov.au/trademarks/search/result';

    protected Client $client;

    protected string $searchWord;

    /**
     * @param string $searchWord
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $searchWord)
    {
        $this->client = new Client();
        if (str_contains($searchWord, ' ')) {
            throw new InvalidArgumentException('Spaces are not allowed');
        }
        $this->searchWord = $searchWord;
    }

    /**
     * Request advanced search page, used to retrieve cookies and CSRF token
     *
     * @return array<\GuzzleHttp\Cookie\CookieJar, string>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exceptions\CsrfTokenNotExtractedException
     */
    public function fetchIndex(): array
    {
        $cookieJar = new CookieJar();
        $advancedSearchIndexResponse = $this->client->get(static::ADVANCED_SEARCH_INDEX_URL, [
            RequestOptions::HEADERS => [
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'accept-language' => 'en-US,en;q=0.9',
                'priority' => 'u=0, i',
                'sec-ch-ua' => '"Google Chrome";v="125", "Chromium";v="125", "Not.A/Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Linux"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'none',
                'sec-fetch-user' => '?1',
                'upgrade-insecure-requests' => '1',
                'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            ],
            RequestOptions::COOKIES => $cookieJar,
        ]);
        $responseContent = $advancedSearchIndexResponse->getBody()->getContents();

        $csrfTokenPattern = '/<meta\s+name="_csrf"\s+content="([^"]+)"/';
        if (preg_match($csrfTokenPattern, $responseContent, $csrfTokenMatches)) {
            $csrfToken = $csrfTokenMatches[1] ?? throw new CsrfTokenNotExtractedException();
        } else {
            throw new CsrfTokenNotExtractedException();
        }

        return [$cookieJar, $csrfToken];
    }

    /**
     * Perform advanced search, does not follow redirect
     *
     * @param \GuzzleHttp\Cookie\CookieJar $cookieJar
     * @param string $csrf
     *
     * @return array<\GuzzleHttp\Cookie\CookieJar, string>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exceptions\SearchUuidNotExtractedException
     */
    public function doSearch(CookieJar $cookieJar, string $csrf): array
    {
        $doSearchRequestCookie = $this->createCookiesString($cookieJar);

        $doSearchResponse = $this->client->post(static::ADVANCED_SEARCH_DO_SEARCH_URL, [
            RequestOptions::HEADERS => [
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'accept-language' => 'en-US,en;q=0.9',
                'cache-control' => 'max-age=0',
                'content-type' => 'application/x-www-form-urlencoded',
                'cookie' => $doSearchRequestCookie,
                'origin' => static::BASE_URL,
                'priority' => 'u=0, i',
                'referer' => static::ADVANCED_SEARCH_INDEX_URL,
                'sec-ch-ua' => '"Google Chrome";v="125", "Chromium";v="125", "Not.A/Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Linux"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'same-origin',
                'sec-fetch-user' => '?1',
                'upgrade-insecure-requests' => '1',
                'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            ],
            RequestOptions::FORM_PARAMS => [
                '_csrf' => $csrf,
                'wv[0]' => $this->searchWord,
                'wt[0]' => 'PART',
                'weOp[0]' => 'AND',
                'wv[1]' => '',
                'wt[1]' => 'PART',
                'wrOp' => 'AND',
                'wv[2]' => '',
                'wt[2]' => 'PART',
                'weOp[1]' => 'AND',
                'wv[3]' => '',
                'wt[3]' => 'PART',
                'iv[0]' => '',
                'it[0]' => 'PART',
                'ieOp[0]' => 'AND',
                'iv[1]' => '',
                'it[1]' => 'PART',
                'irOp' => 'AND',
                'iv[2]' => '',
                'it[2]' => 'PART',
                'ieOp[1]' => 'AND',
                'iv[3]' => '',
                'it[3]' => 'PART',
                'wp' => '',
                '_sw' => 'on',
                'classList' => '',
                'ct' => 'A',
                'status' => '',
                'dateType' => 'LODGEMENT_DATE',
                'fromDate' => '',
                'toDate' => '',
                'ia' => '',
                'gsd' => '',
                'endo' => '',
                'nameField[0]' => 'OWNER',
                'name[0]' => '',
                'attorney' => '',
                'oAcn' => '',
                'idList' => '',
                'ir' => '',
                'publicationFromDate' => '',
                'publicationToDate' => '',
                'i' => '',
                'c' => '',
                'originalSegment' => '',
            ],
            RequestOptions::COOKIES => $cookieJar,
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);

        $redirectUrl = $doSearchResponse->getHeader('Location')[0]
            ?? throw new SearchUuidNotExtractedException('Location header not present in response headers');
        $searchUuid = substr($redirectUrl, strpos($redirectUrl, '?s=') + 3)
            ?? throw new SearchUuidNotExtractedException('Search UUID not found in the redirect URL');

        return [$cookieJar, $searchUuid];
    }

    /**
     * Collect results into an array and also save results as JSON
     *
     * @param \GuzzleHttp\Cookie\CookieJar $cookieJar
     * @param string $searchUuid
     *
     * @return array<int, array<string, mixed>> searchResults
     *
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\LogicalException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function collectResults(CookieJar $cookieJar, string $searchUuid): array
    {
        $pageIndex = 0;
        $results = [];
        do {
            [$cookieJar, $resultsPageResponseContent] = $this->getResultsPageResponse($cookieJar, $searchUuid, $pageIndex);
            /**
             * @var array<int, array<string, mixed>> $pageResults
             * @var integer $numberOfPages
             */
            [$pageResults, $numberOfPages] = $this->parseHtml($resultsPageResponseContent);
            $results += $pageResults;
            $pageIndex++;
        } while ($pageIndex < $numberOfPages);

        return $results;
    }

    /**
     * Used to create a Cookie HTTP request header
     *
     * @param \GuzzleHttp\Cookie\CookieJar $cookieJar
     *
     * @return string
     */
    protected function createCookiesString(CookieJar $cookieJar): string
    {
        $cookies = $cookieJar->toArray();
        if (count($cookies) === 0) {
            return '';
        }

        $result = [];
        foreach ($cookies as $cookie) {
            $result[] = "{$cookie['Name']}={$cookie['Value']}";
        }

        return implode('; ', $result);
    }

    /**
     * @param \GuzzleHttp\Cookie\CookieJar $cookieJar
     * @param string $searchUuid
     * @param int $page
     *
     * @return array<\GuzzleHttp\Cookie\CookieJar, string>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getResultsPageResponse(CookieJar $cookieJar, string $searchUuid, int $page): array
    {
        $collectResultsRequestCookie = $this->createCookiesString($cookieJar);

        $resultsPageResponse = $this->client->get(static::ADVANCED_SEARCH_RESULTS_URL, [
            RequestOptions::QUERY => [
                's' => $searchUuid,
                'p' => $page,
            ],
            RequestOptions::HEADERS => [
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'accept-language' => 'en-US,en;q=0.9',
                'cache-control' => 'max-age=0',
                'cookie' => $collectResultsRequestCookie,
                'priority' => 'u=0, i',
                'referer' => static::ADVANCED_SEARCH_INDEX_URL,
                'sec-ch-ua' => '"Google Chrome";v="125", "Chromium";v="125", "Not.A/Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Linux"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'same-origin',
                'sec-fetch-user' => '?1',
                'upgrade-insecure-requests' => '1',
                'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            ],
            RequestOptions::COOKIES => $cookieJar,
        ]);

        return [$cookieJar, $resultsPageResponse->getBody()->getContents()];
    }

    /**
     * Parser results page
     *
     * 1 page contains 100 items maximum
     *
     * @param string $html
     *
     * @return array<array<int, array<string, mixed>>, int>
     *
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\LogicalException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    protected function parseHtml(string $html): array
    {
        $dom = new Dom();
        $dom->loadStr($html);

        $lastPageNodeCollection = $dom->find('.button.green.no-fill.square.goto-last-page');
        //if last page button is not present it means that search has only 1 page
        $numberOfPages = count($lastPageNodeCollection)
            ? ($lastPageNodeCollection->getAttribute('data-gotopage') + 1)
            : 1;

        $pageResults = [];
        $tbodies = $dom->find('tbody');
        foreach ($tbodies as $i => $tbody) {
            $tr = $tbody->find('tr');
            $index = $tr->find('.col.c-5.table-index span')->text();
            $number = $tr->find('.number a')->text();
            //sometimes images are not present
            $urlLogo = count($tr->find('.trademark.image img'))
                ? $tr->find('.trademark.image img')->getAttribute('src')
                : null;
            $name = htmlspecialchars_decode(trim($tr->find('.trademark.words')->text()));
            $class = trim($tr->find('.classes')->text());
            //different statuses may have different selectors
            $status = trim($tr->find('.status')->text()) ?: trim($dom->find('.status span')->text());
            $urlDetailsPage = $tr->getAttribute('data-markurl');
            $urlDetailsPage = static::BASE_URL . substr($urlDetailsPage, 0, strpos($urlDetailsPage, '?s='));

            $row = [
                'number' => $number,
                'url_logo' => $urlLogo,
                'name' => $name,
                'class' => $class,
                'status' => $status,
                'url_details_page' => $urlDetailsPage,
            ];
            $pageResults[$index] = $row;
        }

        return [$pageResults, $numberOfPages];
    }
}
