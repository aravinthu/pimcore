<?php

namespace Pimcore\Bundle\PimcoreBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Pimcore\Tool;
use Pimcore\Cache as CacheManager;
use Pimcore\Logger;

class FullPageCacheListener implements EventSubscriberInterface
{
    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var null|int
     */
    protected $lifetime = null;

    /**
     * @var bool
     */
    protected $addExpireHeader = true;

    /**
     * @var string|null
     */
    protected $disableReason;

    /**
     * @var string
     */
    protected $defaultCacheKey;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $events = [];

        if(\Pimcore\Tool::isFrontend() && !\Pimcore\Tool::isFrontentRequestByAdmin()) {
            $events = [
                KernelEvents::REQUEST => ['onKernelRequest', -999], // the first
                KernelEvents::RESPONSE => ['onKernelResponse', 9999] // the last
            ];
        }

        return $events;
    }

    /**
     * @param null $reason
     * @return bool
     */
    public function disable($reason = null)
    {
        if ($reason) {
            $this->disableReason = $reason;
        }

        $this->enabled = false;

        return true;
    }

    /**
     * @return bool
     */
    public function enable()
    {
        $this->enabled = true;
        return true;
    }

    /**
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }

    /**
     * @param $lifetime
     * @return $this
     */
    public function setLifetime($lifetime)
    {
        $this->lifetime = $lifetime;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getLifetime()
    {
        return $this->lifetime;
    }

    /**
     *
     */
    public function disableExpireHeader()
    {
        $this->addExpireHeader = false;
    }

    /**
     *
     */
    public function enableExpireHeader()
    {
        $this->addExpireHeader = true;
    }

    /**
     * @param GetResponseEvent $event
     * @return bool
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        $requestUri = $request->getRequestUri();
        $excludePatterns = [];

        // only enable GET method
        if (!$request->isMethod("GET")) {
            return $this->disable();
        }

        // disable the output-cache if browser wants the most recent version
        // unfortunately only Chrome + Firefox if not using SSL
        if (!$request->isSecure()) {
            if (isset($_SERVER["HTTP_CACHE_CONTROL"]) && $_SERVER["HTTP_CACHE_CONTROL"] == "no-cache") {
                return $this->disable("HTTP Header Cache-Control: no-cache was sent");
            }

            if (isset($_SERVER["HTTP_PRAGMA"]) && $_SERVER["HTTP_PRAGMA"] == "no-cache") {
                return $this->disable("HTTP Header Pragma: no-cache was sent");
            }
        }

        try {
            $conf = \Pimcore\Config::getSystemConfig();
            if ($conf->cache) {
                $conf = $conf->cache;

                if (!$conf->enabled) {
                    return $this->disable();
                }

                if (\Pimcore::inDebugMode()) {
                    return $this->disable("in debug mode");
                }

                if ($conf->lifetime) {
                    $this->setLifetime((int) $conf->lifetime);
                }

                if ($conf->excludePatterns) {
                    $confExcludePatterns = explode(",", $conf->excludePatterns);
                    if (!empty($confExcludePatterns)) {
                        $excludePatterns = $confExcludePatterns;
                    }
                }

                if ($conf->excludeCookie) {
                    $cookies = explode(",", strval($conf->excludeCookie));

                    foreach ($cookies as $cookie) {
                        if (!empty($cookie) && isset($_COOKIE[trim($cookie)])) {
                            return $this->disable("exclude cookie in system-settings matches");
                        }
                    }
                }

                // output-cache is always disabled when logged in at the admin ui
                if (isset($_COOKIE["pimcore_admin_sid"])) {
                    return $this->disable("backend user is logged in");
                }
            } else {
                return $this->disable();
            }
        } catch (\Exception $e) {
            Logger::error($e);

            return $this->disable("ERROR: Exception (see debug.log)");
        }

        foreach ($excludePatterns as $pattern) {
            if (@preg_match($pattern, $requestUri)) {
                return $this->disable("exclude path pattern in system-settings matches");
            }
        }

        $deviceDetector = Tool\DeviceDetector::getInstance();
        $device = $deviceDetector->getDevice();
        $deviceDetector->setWasUsed(false);

        $appendKey = "";
        // this is for example for the image-data-uri plugin
        if (isset($_REQUEST["pimcore_cache_tag_suffix"])) {
            $tags = $_REQUEST["pimcore_cache_tag_suffix"];
            if (is_array($tags)) {
                $appendKey = "_" . implode("_", $tags);
            }
        }

        $this->defaultCacheKey = "output_" . md5(\Pimcore\Tool::getHostname() . $requestUri . $appendKey);
        $cacheKeys = [
            $this->defaultCacheKey . "_" . $device,
            $this->defaultCacheKey,
        ];

        $cacheKey = null;
        $cacheItem = null;
        foreach ($cacheKeys as $cacheKey) {
            $cacheItem = CacheManager::load($cacheKey, true);
            if ($cacheItem) {
                break;
            }
        }

        if ($cacheItem) {
            /**
             * @var $response Response
             */
            $response = $cacheItem;
            $response->headers->set("X-Pimcore-Output-Cache-Tag", $cacheKey, true);
            $cacheItemDate = strtotime($response->headers->get("X-Pimcore-Cache-Date"));
            $response->headers->set("Age", (time()-$cacheItemDate));

            $response->send();
            exit;
        }
    }

    /**
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $response = $event->getResponse();

        if ($this->enabled && session_id()) {
            $this->disable("session in use");
        }

        if ($this->disableReason) {
            $response->headers->set("X-Pimcore-Output-Cache-Disable-Reason", $this->disableReason, true);
        }

        if ($this->enabled && $response->getStatusCode() == 200) {
            try {
                if ($this->lifetime && $this->addExpireHeader) {
                    // add cache control for proxies and http-caches like varnish, ...
                    $response->headers->set("Cache-Control", "public, max-age=" . $this->lifetime, true);

                    // add expire header
                    $date = new \DateTime("now");
                    $date->add(new \DateInterval("PT" . $this->lifetime . "S"));
                    $response->headers->set("Expires", $date->format(\DateTime::RFC1123), true);
                }

                $now = new \DateTime("now");
                $response->headers->set("X-Pimcore-Cache-Date", $now->format(\DateTime::ISO8601));

                $cacheKey = $this->defaultCacheKey;
                $deviceDetector = Tool\DeviceDetector::getInstance();
                if ($deviceDetector->wasUsed()) {
                    $cacheKey .= "_" . $deviceDetector->getDevice();
                }

                $cacheItem = $response;

                $tags = ["output"];
                if ($this->lifetime) {
                    $tags = ["output_lifetime"];
                }

                CacheManager::save($cacheItem, $cacheKey, $tags, $this->lifetime, 1000, true);
            } catch (\Exception $e) {
                Logger::error($e);

                return;
            }
        } else {
            // output-cache was disabled, add "output" as cleared tag to ensure that no other "output" tagged elements
            // like the inc and snippet cache get into the cache
            CacheManager::addClearedTag("output_inline");
        }
    }
}