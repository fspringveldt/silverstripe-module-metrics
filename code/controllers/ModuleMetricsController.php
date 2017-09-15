<?php

class ModuleMetricsController extends Controller
{
    private static $allowed_actions = [
        'ping',
        'index' => '->ping',
        'json' => '->json'
    ];

    public function index()
    {
        $result = ModuleMetrics::inst()->resultAsList();
        return $this->customise(['Metrics' => $result])->renderWith([__CLASS__, 'Page']);
    }

    public function json()
    {
        return ModuleMetrics::inst()->toJSON();
    }

    public function ping()
    {
        return (bool)$this->config()->get('share_usage_with_silverstripe');
    }
}