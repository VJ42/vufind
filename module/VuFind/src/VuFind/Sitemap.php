<?php
/**
 * VuFind Sitemap
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Sitemap
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://www.vufind.org  Main Page
 */
namespace VuFind;
use VuFindSearch\Backend\Solr\Backend, Zend\Config\Config;

/**
 * Class for generating sitemaps
 *
 * @category VuFind2
 * @package  Sitemap
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://www.vufind.org  Main Page
 */
class Sitemap
{
    /**
     * Search backend from which to retrieve record IDs.
     *
     * @var Backend
     */
    protected $backend;

    /**
     * Base URL for site
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Base URL for record
     *
     * @var string
     */
    protected $resultUrl;

    /**
     * Sitemap configuration (sitemap.ini)
     *
     * @var Config
     */
    protected $config;

    /**
     * Frequency of URL updates (always, daily, weekly, monthly, yearly, never)
     *
     * @var string
     */
    protected $frequency;

    /**
     * URL entries per sitemap
     *
     * @var int
     */
    protected $countPerPage;

    /**
     * Base path to sitemap files, including base filename
     *
     * @var string
     */
    protected $fileStart;

    /**
     * Filename of sitemap index
     *
     * @var string
     */
    protected $indexFile = false;

    /**
     * Warnings thrown during sitemap generation
     *
     * @var array
     */
    protected $warnings = array();

    /**
     * Constructor
     *
     * @param Backend $backend Search backend
     * @param string  $baseUrl VuFind base URL
     * @param Config  $config  Sitemap configuration settings
     */
    public function __construct(Backend $backend, $baseUrl, Config $config)
    {
        $this->backend = $backend;
        $this->baseUrl = $baseUrl;
        $this->resultUrl = $this->baseUrl . '/Record/';
        $this->config = $config;
        $this->frequency = $this->config->Sitemap->frequency;
        $this->countPerPage = $this->config->Sitemap->countPerPage;
        $this->fileStart = $this->config->Sitemap->fileLocation . "/" .
            $this->config->Sitemap->fileName;
        if (isset($this->config->SitemapIndex->indexFileName)) {
            $this->indexFile = $this->config->Sitemap->fileLocation . "/" .
                $this->config->SitemapIndex->indexFileName. ".xml";
        }
    }

    /**
     * Generate the sitemaps based on settings established by the constructor.
     *
     * @return void
     */
    public function generate()
    {
        $currentPage = 1;
        $last_term = '';

        while (true) {
            // Get
            $currentPageInfo
                = $this->backend->terms('id', $last_term, $this->countPerPage)
                    ->getFieldTerms('id');
            if (null === $currentPageInfo || count($currentPageInfo) < 1) {
                break;
            } else {
                $filename = $this->getFilenameForPage($currentPage);
                $smf = $this->openSitemapFile($filename, 'urlset');
                foreach ($currentPageInfo as $item => $count) {
                    $loc = htmlspecialchars($this->resultUrl . urlencode($item));
                    if (strpos($loc, 'http') === false) {
                        $loc = 'http://'.$loc;
                    }
                    $this->writeSitemapEntry($smf, $loc);
                    $last_term = $item;
                }

                fwrite($smf, '</urlset>');
                fclose($smf);
            }

            $currentPage++;
        }

        // Set-up Sitemap Index
        $this->buildIndex($currentPage - 1);
    }

    /**
     * Get array of warning messages thrown during build.
     *
     * @return array
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * Write a sitemap index if requested.
     *
     * @param int $totalPages Total number of sitemap pages generated.
     *
     * @return void
     */
    protected function buildIndex($totalPages)
    {
        // Only build index file if requested:
        if ($this->indexFile !== false) {
            $smf = $this->openSitemapFile($this->indexFile, 'sitemapindex');

            // Add a <sitemap /> group for a static sitemap file.
            // See sitemap.ini for more information on this option.
            if (isset($this->config->SitemapIndex->baseSitemapFileName)) {
                $baseSitemapFile = $this->config->Sitemap->fileLocation . "/" .
                    $this->config->SitemapIndex->baseSitemapFileName . ".xml";
                // Only add the <sitemap /> group if the file exists
                // in the directory where the other sitemap files
                // are saved, i.e. ['Sitemap']['fileLocation']
                if (file_exists($baseSitemapFile)) {
                    $this->writeSitemapIndexLine(
                        $smf, $this->config->SitemapIndex->baseSitemapFileName
                    );
                } else {
                    $this->warnings[] = "WARNING: Can't open file "
                        . $baseSitemapFile . '. '
                        . 'The sitemap index will be generated '
                        . "without this sitemap file.";
                }
            }

            // Add <sitemap /> group for each sitemap file generated.
            for ($i = 1; $i <= $totalPages; $i++) {
                $sitemapNumber = ($i == 1) ? "" : "-" . $i;
                $this->writeSitemapIndexLine(
                    $smf, $this->config->Sitemap->fileName . $sitemapNumber
                );
            }

            fwrite($smf, '</sitemapindex>');
            fclose($smf);
        }
    }

    /**
     * Get the filename for the specified page number.
     *
     * @param int $page Page number
     *
     * @return string
     */
    protected function getFilenameForPage($page)
    {
        return $this->fileStart . ($page == 1 ? '' : '-' . $page) . '.xml';
    }

    /**
     * Start writing a sitemap file (including the top-level open tag).
     *
     * @param string $filename Filename to open.
     * @param string $startTag Top-level tag in file.
     *
     * @return int             File handle of open file.
     */
    protected function openSitemapFile($filename, $startTag)
    {
        // if a subfolder was specified that does not exist, make one
        $dirname = dirname($filename);
        if (!is_dir($dirname)) {
            mkdir($dirname, 0755, true);
        }
        // open/create new file
        $smf = fopen($filename, 'w');

        if (!$smf) {
            throw new \Exception("Can't open file - " . $filename);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<' . $startTag . "\n" .
            '   xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n" .
            '   xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n" .
            "   xsi:schemaLocation=\"http://www.sitemaps.org/schemas/sitemap/0.9\n" .
            '   http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n\n";
        fwrite($smf, $xml);

        return $smf;
    }

    /**
     * Write an entry to a sitemap.
     *
     * @param int    $smf File handle to write to.
     * @param string $loc URL to write.
     *
     * @return void
     */
    protected function writeSitemapEntry($smf, $loc)
    {
        fwrite($smf, '<url>' . "\n");
        fwrite($smf, '  <loc>' . $loc . '</loc>' . "\n");
        fwrite(
            $smf,
            '  <changefreq>'.htmlspecialchars($this->frequency)
            .'</changefreq>'."\n"
        );
        fwrite($smf, '</url>' . "\n");
    }

    /**
     * Write a line to the sitemap index file.
     *
     * @param int    $smf      File handle to write to.
     * @param string $filename Filename (not including path) to store.
     *
     * @return void
     */
    protected function writeSitemapIndexLine($smf, $filename)
    {
        // Pick the appropriate base URL based on the configuration files:
        if (!isset($this->config->SitemapIndex->baseSitemapUrl)
            || empty($this->config->SitemapIndex->baseSitemapUrl)
        ) {
            $baseUrl = $this->baseUrl;
        } else {
            $baseUrl = $this->config->SitemapIndex->baseSitemapUrl;
        }

        $loc = htmlspecialchars($baseUrl.'/'.$filename.'.xml');
        $lastmod = htmlspecialchars(date("Y-m-d"));
        fwrite($smf, '  <sitemap>' . "\n");
        fwrite($smf, '    <loc>' . $loc . '</loc>' . "\n");
        fwrite($smf, '    <lastmod>' . $lastmod . '</lastmod>' . "\n");
        fwrite($smf, '  </sitemap>' . "\n");
    }
}