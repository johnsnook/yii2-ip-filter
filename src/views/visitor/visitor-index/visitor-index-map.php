<?php

/**
 * @author John Snook
 * @date Aug 23, 2018
 * @license https://snooky.biz/site/license
 * @copyright 2018 John Snook Consulting
 * Description of visits_map
 */
/* @var $this yii\web\View */

use johnsnook\visitors\assets\LeafletAsset;

LeafletAsset::register($this);
$this->registerJs(file_get_contents(__dir__ . '/visitor-index-map.js'));
?>
<style>
    html, body {
        height: 100%;
        margin: 0;
    }
    #map {
        width: 100%;
        height: 500px;
    }
</style>

<div id="map"></div>
<h2>Oranization Info</h2>
<p id="pumpme"></p>