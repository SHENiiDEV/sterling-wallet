<?php

namespace App\Reports\Parsers;

use App\Models\Provider;
use InvalidArgumentException;

class ParserRegistry
{
    /**
     * @param  iterable<ReportParser>  $parsers
     */
    public function __construct(private iterable $parsers) {}

    public function for(Provider $provider): ReportParser
    {
        $format = $provider->report_format;
        if ($format === null || $format === '') {
            throw new InvalidArgumentException("Provider {$provider->code} has no report format set.");
        }

        return $this->forFormat($format);
    }

    public function forFormat(string $format): ReportParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($format)) {
                return $parser;
            }
        }

        throw new InvalidArgumentException("No parser for report format [{$format}].");
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        $formats = [];
        foreach ($this->parsers as $parser) {
            if ($parser instanceof HeaderMappedParser) {
                $formats[] = $parser->format();
            }
        }

        return $formats;
    }
}
