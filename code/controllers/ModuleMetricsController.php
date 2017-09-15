<?php

class ModuleMetricsController extends Controller
{
    private static $allowed_actions = [
        'ping',
        'index' => '->ping'
    ];

    public function index()
    {
        $result = ModuleMetrics::inst()->resultAsList();
        return $this->customise(['Metrics' => $result])->renderWith([__CLASS__, 'Page']);
    }

    public function ping()
    {
        return (bool)$this->config()->get('share_usage_with_silverstripe');
    }
}