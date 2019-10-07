<?php
/*
 * Copyright (c) 2019, The Jaeger Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Jaeger\Transport;

use Jaeger\Tracer;
use Jaeger\Thrift\AgentClient;
use Jaeger\Thrift\JaegerThriftSpan;
use Jaeger\Thrift\Process;
use Jaeger\Thrift\Span;
use Jaeger\Thrift\TStruct;
use Jaeger\UdpClient;
use Thrift\Transport\TMemoryBuffer;
use Thrift\Protocol\TCompactProtocol;
use Jaeger\Constants;

/**
 * Class TransportUdp
 * @package Jaeger\Transport
 */
class TransportUdp implements TransportInterface {

    /** @var TMemoryBuffer */
    private $buffer;

    public $hostPort = '';

    public static $maxSpanBytes = 0;

    public static $batches = [];

    public $agentServerHostPort = '0.0.0.0:5775';

    public $thriftProtocol;

    public $processSize = 0;

    public $bufferSize = 0;

    public function __construct(
        ?string $hostPort = '',
        ?string $maxPacketSize = ''
    ) {
        $parts = explode('//', $hostPort);
        $hostPort = end($hostPort);
        $this->hostPort = empty($hostPort)? $this->agentServerHostPort: $hostPort;

        if ($maxPacketSize == 0) {
            $maxPacketSize = Constants\UDP_PACKET_MAX_LENGTH;
        }
        self::$maxSpanBytes = $maxPacketSize - Constants\EMIT_BATCH_OVER_HEAD;

        $this->buffer = new TMemoryBuffer();
        $this->thriftProtocol = new TCompactProtocol($this->buffer);
    }

    public function buildAndCalcSizeOfProcessThrift(Tracer $jaeger): void
    {
        $jaeger->processThrift = (new JaegerThriftSpan())->buildJaegerProcessThrift($jaeger);
        $jaeger->process = (new Process($jaeger->processThrift));
        $this->processSize = $this->getAndCalcSizeOfSerializedThrift($jaeger->process, $jaeger->processThrift);
        $this->bufferSize += $this->processSize;
    }

    public function append(Tracer $tracer) {

        if ($tracer->process == null) {
            $this->buildAndCalcSizeOfProcessThrift($tracer);
        }

        $spanThrifts = [];
        foreach ($tracer->spans as $span) {
            $spanThrift = (new JaegerThriftSpan())->buildJaegerSpanThrift($span);
            /** @var Span $agentSpan */
            $agentSpan = Span::getInstance();
            $agentSpan->setThriftSpan($spanThrift);
            $spanSize = $this->getAndCalcSizeOfSerializedThrift($agentSpan, $spanThrift);
            if ($spanSize > self::$maxSpanBytes) {
                continue;
            }
            $this->bufferSize += $spanSize;
            if($this->bufferSize > self::$maxSpanBytes){
                self::$batches[] = [
                    'thriftProcess' => $tracer->processThrift,
                    'thriftSpans' => $spanThrifts
                ];
                $spanThrifts = [];
                $this->bufferSize = 0;
            }
            $spanThrifts[] = $spanThrift;
        }

        self::$batches[] = [
            'thriftProcess' => $tracer->processThrift,
            'thriftSpans' => $spanThrifts
        ];

        return true;
    }

    public function resetBuffer(): void
    {
        $this->bufferSize = $this->processSize;
        self::$batches = [];
    }

    private function getAndCalcSizeOfSerializedThrift(TStruct $ts, &$serializedThrift) {
        $ts->write($this->thriftProtocol);
        $serThriftStrlen = $this->buffer->available();
        $serializedThrift['wrote'] = $this->buffer->read(Constants\UDP_PACKET_MAX_LENGTH);

        return $serThriftStrlen;
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function flush(): int
    {
        $batchNum = count(self::$batches);
        if ($batchNum <= 0) {
            return 0;
        }

        $spanNum = 0;
        $udp = new UdpClient($this->hostPort, new AgentClient());

        foreach (self::$batches as $batch){
            $spanNum += count($batch['thriftSpans']);
            $udp->emitBatch($batch);
        }

        $udp->close();
        $this->resetBuffer();

        return (int) $spanNum;
    }

    /**
     * @return array
     */
    public function getBatches(): array
    {
        return self::$batches;
    }
}
