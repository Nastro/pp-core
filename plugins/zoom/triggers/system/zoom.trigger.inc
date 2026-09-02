<?php

class PXTriggerSystemZoom extends PXAbstractSystemTrigger
{
    public $app;
    public $layout;

    public function onAfterEngineStart($engine)
    {
        PXRegistry::assignToObject($this);

        if (!$this->app->isAdminEngine() && $this->layout instanceof \PP\Lib\Html\Layout\UserLayoutInterface) {
            $this->layout->addFilter(['PXZoomFilter', 'run']);
        }
    }
}

?>
