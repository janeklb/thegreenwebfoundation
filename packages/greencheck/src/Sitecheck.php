<?php

namespace TGWF\Greencheck;

use Doctrine\ORM\EntityManager;
use TGWF\Greencheck\Entity\GreencheckIp;
use TGWF\Greencheck\Repository\GreencheckAsRepository;
use TGWF\Greencheck\Repository\GreencheckIpRepository;
use TGWF\Greencheck\Repository\GreencheckTldRepository;
use TGWF\Greencheck\Repository\GreencheckUrlRepository;
use TGWF\Greencheck\Sitecheck\Cache;
use TGWF\Greencheck\Sitecheck\DnsFetcher;
use TGWF\Greencheck\Sitecheck\Logger;
use TGWF\Greencheck\Sitecheck\Validator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints\Ip;

/**
 * Sitecheck class.
 *
 * The sitecheck handles all actions with regard to the Green Web Foundation greencheck.
 *
 * Flow :
 * - Check the cached records for an url, if found return
 * - Check the customer records for an url, if found return
 * - Check the ip records for an url, if found return
 * - Check the as records for an url, if found return
 * - None found, then return url = grey
 *
 * @author Arend-Jan Tetteroo <aj@arendjantetteroo.nl>
 */
class Sitecheck
{
    /**
     * Error messages from validation.
     *
     * @var array
     */
    protected $_errorMessages = null;

    /**
     * Should the checks be logged.
     *
     * @var boolean, defaults to true
     */
    protected $_log = true;

    /**
     * @var Validator
     */
    protected $validator = null;

    /**
     * @var Sitecheck\Cache
     */
    protected $cache = null;

    /**
     * Needed for log purposes (website|admin|test|bot).
     *
     * @var string
     */
    protected $_calledfrom = 'website';

    /**
     * Ip's for checked url's to skip hostname checks on each call.
     *
     * @var array
     */
    protected $_ipforurl = [];

    /**
     * Ip's for checked url's to skip hostname checks on each call.
     *
     * @var array
     */
    protected $cleanurl = [];

    /**
     * The domains we have knowledge on.
     *
     * @var <type>
     */
    protected $_countrytlds = null;

    /**
     * @var GreencheckUrlRepository
     */
    protected $greencheckUrl = null;

    /**
     * @var GreencheckIpRepository
     */
    protected $greencheckIp = null;

    /**
     * @var GreencheckAsRepository
     */
    protected $greencheckAs = null;

    protected $aschecker = null;

    /**
     * @var GreencheckTldRepository
     */
    private $greencheckTld;
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Construct the sitecheck.
     *
     * @param GreencheckUrlRepository $greencheckUrlRepository
     * @param GreencheckIpRepository  $greencheckIpRepository
     * @param GreencheckAsRepository  $greencheckAsRepository
     * @param GreencheckTldRepository $greencheckTldRepository
     * @param Cache $cache
     * @param string $calledfrom [description]
     */
    public function __construct(
        GreencheckUrlRepository $greencheckUrlRepository,
        GreencheckIpRepository $greencheckIpRepository,
        GreencheckAsRepository $greencheckAsRepository,
        GreencheckTldRepository $greencheckTldRepository,
        Cache $cache,
        Logger $logger,
        $calledfrom = 'website',
        DnsFetcher $dnsFetcher = null
    ) {
        $this->_calledfrom = $calledfrom;

        $this->validator = new Validator();
        if (null == $dnsFetcher) {
            $dnsFetcher = new DnsFetcher();
        }
        $this->dnsFetcher = $dnsFetcher;

        $this->greencheckUrl = $greencheckUrlRepository;
        $this->greencheckIp = $greencheckIpRepository;
        $this->greencheckAs = $greencheckAsRepository;
        $this->greencheckTld = $greencheckTldRepository;

        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Check if the given url is a valid Hostname, and if so, check that it returns a valid ip adress.
     *
     * @param string $url
     *
     * @return bool
     */
    public function validate($url)
    {
        $url = $this->validator->getHostname($url);
        $ips = $this->getIpForUrl($url);

        return $this->validator->validate($url, $ips);
    }

    /**
     * Return eventual validation errors.
     *
     * @return array
     */
    public function getValidateErrors()
    {
        return $this->validator->getValidateErrors();
    }

    public function getValidator()
    {
        return $this->validator;
    }

    /**
     * Check the url in the greencheck, flow see above.
     *
     * @param string $url The url to check
     *
     * @return SitecheckResult|false
     */
    public function check($url, $checked_by = '0', $checked_browser = '', $checked_through = '')
    {
        $url = $this->validator->getHostname($url);

        $validurl = $this->validate($url);
        // Incorrect url, then return
        if (false == $validurl) {
            return $validurl;
        }

        if ('' == $checked_through) {
            $checked_through = $this->_calledfrom;
        }

        // Result is cached, then return result
        if ($result = $this->getCache('result')->fetch(sha1($url))) {
            $result->setCalledFrom(
                [
                    'checked_by' => $checked_by,
                    'checked_browser' => $checked_browser,
                    'checked_through' => $checked_through,
                    ]
            );
            $result->setCheckedAt(new \DateTime('now'));
            $this->logResult($result);
            $result->setCached(true);

            return $result;
        }

        $result = new SitecheckResult($url, $this->getIpForUrl($url));

        $result->setCalledFrom(
            [
                'checked_by' => $checked_by,
                'checked_browser' => $checked_browser,
                'checked_through' => $checked_through,
            ]
        );

        // Check both www.domain.tld and domain.tld
        if ('www.' == substr($url, 0, 4)) {
            $strippedurl = substr($url, 4);
        } else {
            $strippedurl = 'www.'.$url;
        }

        // Compensated/Greened by Cleanbits?
        $customerResult = $this->greencheckUrl->checkUrl($url);
        if (!is_null($customerResult)) {
            return $this->updateCustomerResult($result, $customerResult);
        }

        // Recheck
        $customerResult = $this->greencheckUrl->checkUrl($strippedurl);
        if (!is_null($customerResult)) {
            return $this->updateCustomerResult($result, $customerResult);
        }

        // Not compensated, check in IP database
        $ipResult = $this->checkIp($url);
        if (!is_null($ipResult)) {
            $matchtext = $ipResult->getIpStart().' - '.$ipResult->getIpEind();

            return $this->updateResult($result, $matchtext, 'ip', $ipResult);
        }

        // Not compensated, check in AS database
        $asResult = $this->checkAs($url);
        if (!is_null($asResult)) {
            $matchtext = $asResult->getAsn();

            return $this->updateResult($result, $matchtext, 'as', $asResult);
        }

        // Check if we have hosting providers for this domain
        $result = $this->checkTld($url, $result);

        // Not found, then not green by default
        $result->setGreen(false);
        $this->cache->setItem('result', $url, clone $result);
        $this->logResult($result);

        return $result;
    }

    /**
     * Check if we have data for the tld of this url.
     *
     * @param string          $url
     * @param SitecheckResult $result
     *
     * @return SitecheckResult
     */
    public function checkTld($url, $result)
    {
        $url = $this->validator->getHostname($url);
        $tlds = $this->getCountryTlds();
        $tld = $this->getTldFromUrl($url);
        if (!isset($tlds[$tld])) {
            $result->setData(false);
        }

        return $result;
    }

    /**
     * Get the tld for the given url.
     *
     * @param string $url
     *
     * @return string
     */
    public function getTldFromUrl($url)
    {
        $splittedurl = explode('.', $url);
        $tld = array_pop($splittedurl);

        return $tld;
    }

    /**
     * Get all tld's for which we have data.
     *
     * @return array
     */
    public function getCountryTlds()
    {
        if (!isset($this->_countrytlds)) {
            $this->_countrytlds = $this->greencheckTld->getTLDsWithData();
        }

        return $this->_countrytlds;
    }

    /**
     * Log the request to the logtable, for clientlist and statistics.
     *
     * @param SitecheckResult $result
     */
    public function logResult($result)
    {
        if (!$this->_log) {
            return;
        }

        $this->logger->logResult($result);
    }

    /**
     * Get the ip belonging to the given url.
     *
     * @param string $url
     *
     * @return string|false
     */
    public function getIpForUrl($url)
    {
        if ($this->validator->isUrlAValidPublicIpAddress($url)) {
            $ip = $url;
            if ($this->validator->isValidIpAddressForType($ip, '4')) {
                // Valid ipv4 adress, assign
                $this->_ipforurl[$url]['ipv4'] = $ip;
                $this->_ipforurl[$url]['ipv6'] = false;
            }
            if ($this->validator->isValidIpAddressForType($ip, '6')) {
                // Valid ipv6 adress, assign
                $this->_ipforurl[$url]['ipv6'] = $ip;
                $this->_ipforurl[$url]['ipv4'] = false;
            }

            return $this->_ipforurl[$url];
        }

        if ($this->validator->isUrlAValidIpAddress($url)) {
            // Valid non public ip adress, return false
            $this->_ipforurl[$url]['ipv4'] = false;
            $this->_ipforurl[$url]['ipv6'] = false;

            return $this->_ipforurl[$url];
        }

        // if we get a null value passed into the sitecheck, it
        // gets coerced to "". We change it here so the return values
        // for $url are in one place
        if ("" == $url) {
            $this->_ipforurl[$url]['ipv4'] = false;
            $this->_ipforurl[$url]['ipv6'] = false;

            return $this->_ipforurl[$url];
        }

        // Real url given, clean it up and get ipadress
        $url = $this->validator->getHostname($url);
        if (!isset($this->_ipforurl[$url])) {
            $hostname = $this->getHostByName($url);

            $ip = $hostname['ip'];
            if (false != $ip && $this->validator->isValidIpAddressForType($ip, '4')) {
                $this->_ipforurl[$url]['ipv4'] = $ip;
            } else {
                $this->_ipforurl[$url]['ipv4'] = false;
            }

            $ipv6 = $hostname['ipv6'];
            $this->_ipforurl[$url]['ipv6'] = $ipv6;
        }

        return $this->_ipforurl[$url];
    }

    /**
     * Get the ipadress for a given url by the gethostbyname function, return cached if available.
     *
     * @param string $url
     *
     * @return string
     */
    public function getHostByName($url)
    {
        if ($result = $this->getCache('hostbynamelookups')->fetch(sha1('hostbyname'.$url))) {
            $result['cached'] = true;

            return $result;
        }

        // Ignore dns warnings
        $result = $this->dnsFetcher->getIpAddressesForUrl($url);

        $result['cached'] = false;
        $this->cache->setItem('hostbynamelookups', 'hostbyname'.$url, $result);

        return $result;
    }

    /**
     * Check if ip of given url is in the $searchdata set.
     *
     * @param string $url
     *
     * @return array|null
     */
    public function checkIp($url)
    {
        $ip = $this->getIpForUrl($url);
        if (false !== $ip['ipv4']) {
            // we don't seem to have a checkIp function on the
            // GreencheckIp class anymore anymore. It is on
            // the repository class
            $result = $this->greencheckIp->checkIp($ip['ipv4']);
            if (!is_null($result)) {
                return $result;
            }
        }

        if (false !== $ip['ipv6']) {
            $result = $this->greencheckIp->checkIp($ip['ipv6']);
        }

        if (!isset($result)) {
            $result = null;
        }

        return $result;
    }

    /**
     * Check if as of given url is in the $searchdata set.
     *
     * @param string $url
     *
     * @return array|null
     */
    public function checkAs($url)
    {
        $as = $this->getAsForUrl($url);

        if (is_null($as)) {
            return null;
        }
        foreach ($as['as'] as $asnumber) {
            $result = $this->greencheckAs->checkAs($asnumber);
            if (!is_null($result)) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Get the AS information for the given url.
     *
     * @param string $url
     *
     * @return array
     */
    public function getAsForUrl($url)
    {
        $aschecker = $this->getAsChecker();

        // Get the ip adress for this url
        $ip = $this->getIpForUrl($url);
        if (false !== $ip['ipv4']) {
            return $aschecker->getAsForIpv4($ip['ipv4']);
        }

        if (false !== $ip['ipv6']) {
            return $aschecker->getAsForIpv6($ip['ipv6']);
        }

        return false; // This should not happen
    }

    public function getAsChecker()
    {
        if (is_null($this->aschecker)) {
            $this->aschecker = new Sitecheck\Aschecker($this->cache);
        }

        return $this->aschecker;
    }

    /**
     * Disable the logging of checks.
     */
    public function disableLog()
    {
        $this->_log = false;
    }

    public function disableCache()
    {
        $this->cache->disableCache();
    }

    public function resetCache($key)
    {
        $this->cache->resetCache($key);
    }

    public function setCache($key, $cache = null)
    {
        $this->cache->setCache($key, $cache);
    }

    public function getCache($key = 'default')
    {
        return $this->cache->getCache($key);
    }

    public function getCacheObject()
    {
        return $this->cache;
    }

    /**
     * Update the sitecheckresult with a hostingprovider enttiy, log the result and cache the result.
     *
     * @param [type] $result      [description]
     * @param [type] $matchtext   [description]
     * @param [type] $matchtype   [description]
     * @param [type] $matchobject [description]
     *
     * @return [type] [description]
     */
    private function updateResult($result, $matchtext, $matchtype, $matchobject)
    {
        // Need an entity and not a proxy object for serializing in the cache
        $hp = $matchobject->getHostingprovider();
        $hpnew = new Entity\Hostingprovider();
        $hpnew->setEntity($hp);

        $result->setGreen(true);
        $result->setMatch($matchobject->getId(), $matchtype, $matchtext);
        $result->setHostingProviderId($hpnew->getId());
        $result->setHostingProvider($hpnew);

        $this->logResult($result);
        $this->cache->setItem('result', $result->getCheckedUrl(), $result);

        return $result;
    }

    private function updateCustomerResult($result, $customerResult)
    {
        $result->setGreen(true);
        $result->setMatch($customerResult->getId(), 'url');
        $this->cache->setItem('result', $result->getCheckedUrl(), $result);
        $this->logResult($result);

        return $result;
    }
}
