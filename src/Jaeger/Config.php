<?php
namespace Jaeger;

use OpenTracing\NoopTracer;
use Jaeger\Reporter\RemoteReporter;
use Jaeger\Reporter\Reporter;
use Jaeger\Transport\TransportUdp;
use Jaeger\Transport\TransportInterface;
use Jaeger\Sampler\Sampler;
use Jaeger\Sampler\ConstSampler;
use Jaeger\Propagator\JaegerPropagator;
use Jaeger\Propagator\ZipkinPropagator;

/**
 * Class Config
 * @package Jaeger
 */
class Config {

    const DEFAULT_SERVER_URL = '0.0.0.0:5775';

    /** @var string */
    private $serverUrl = self::DEFAULT_SERVER_URL;

    private $sampler = null;

    private $scopeManager = null;

    private $gen128bit = false;

    /** @var Tracer[] */
    public static $tracers;

    public static $span = null;

    public static $instance = null;

    public static $disabled = false;

    public static $propagator = \Jaeger\Constants\PROPAGATOR_JAEGER;

    /**
     * @return Config
     */
    public static function getInstance(): self
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $serviceName
     * @param string $agentHostPort
     * @return Tracer|null
     * @throws \Exception
     */
    public function initTracer(string $serverName, ?string $serverUrl = null) {

        if (self::$disabled) {
            return NoopTracer::create();
        }

        if (empty($serverName)) {
            throw new \Exception("Server name is required");
        }

        if(!empty(self::$tracers[$serverName])) {
            return self::$tracers[$serverName];
        }

        $serverUrl = $serverUrl ?: $this->serverUrl;
        $transport = new TransportUdp($serverUrl);

        if (!$this->sampler) {
            $this->sampler = new ConstSampler(true);
        }

        if (!$this->scopeManager) {
            $this->scopeManager = new ScopeManager();
        }

        $tracer = new Tracer($transport, $this->sampler, $this->scopeManager, $serverName);
        if ($this->gen128bit) {
            $tracer->gen128bit();
        }
        $propagator = (self::$propagator == \Jaeger\Constants\PROPAGATOR_ZIPKIN) ?
            new ZipkinPropagator() : new JaegerPropagator();
        $tracer->setPropagator($propagator);

        return self::$tracers[$serverName] = $tracer;
    }

    /**
     * @param string $serverUrl
     * @return Config
     */
    public function setServerUrl(string $serverUrl): self
    {
        $this->serverUrl = $serverUrl; //@todo validate url

        return $this;
    }

    /**
     * close tracer
     * @param $disabled
     */
    public function setDisabled($disabled){
        self::$disabled = $disabled;

        return $this;
    }

    public function setSampler(Sampler $sampler) {
        $this->sampler = $sampler;

        return $this;
    }

    public function gen128bit() {
        $this->gen128bit = true;

        return $this;
    }

    public function flush(): bool
    {
        if (count(self::$tracers) > 0) {
            foreach(self::$tracers as $tracer) {
                $tracer->flush();
            }
        }

        return true;
    }
}
