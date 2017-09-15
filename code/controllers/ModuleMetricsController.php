<?php

class ModuleMetricsController extends Controller
{
    public function index()
    {
        $result = ModuleMetrics::inst()->resultAsList();
        return $this->customise(['Metrics' => $result])->renderWith([__CLASS__, 'Page']);
    }
}