<?php
/*
 * TileServer.php project
 * ======================
 * https://github.com/klokantech/tileserver-php/
 * Copyright (C) 2012 - Klokan Technologies GmbH
 */

require "tileserver.php";

header("Content-type: application/xml");

echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n"; 

// Accepted GET strings
$layer = (array_key_exists('layer', $_GET)) ? $_GET['layer'] : "";

# -----------
# TMS SERVICE
# -----------
if ($layer === ""):
  
  $maps = maps();
?>
<TileMapService version="1.0.0">
  <TileMaps>
<?php
  foreach ($maps as $m) {
    $basename = str_replace('.mbtiles', '', $m['basename']);
    $title = (array_key_exists('name', $m )) ? $m['name'] : $basename;
    $profile = $m['profile'];
    if ($profile == 'geodetic')
      $srs = "EPSG:4326";
    else
      $srs = "EPSG:3857";
    echo "    <TileMap title=\"$title\" srs=\"$srs\" type=\"InvertedTMS\" profile=\"global-$profile\" href=\"$baseUrl$basename/tms\" />\n";
  }
  ?>
</TileMapService>
<?php
die;
# ---------
# TMS LAYER
# ---------
else:
    $m = layer($layer);
    $basename = str_replace('.mbtiles', '', $m['basename']);
    $title = (array_key_exists('name', $m )) ? $m['name'] : $basename;
    $description = (array_key_exists('description', $m )) ? $m['description'] : "";
    $bounds = $m['bounds'];
    $profile = $m['profile'];
    if ($profile == 'geodetic') {
      $srs = "EPSG:4326";
      $originx = -180.0;
      $originy = -90.0;
      $initialResolution = 0.703125;
    }
    else {
      $srs = "EPSG:3857";
      $originx = -20037508.342789;
      $originy = -20037508.342789;
      list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
      list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
      $bounds = array( $minx, $miny, $maxx, $maxy );
      $initialResolution = 156543.03392804062;
    }
    $format = $m['format'];
    $mime = ($format == 'jpg') ? 'image/jpeg' : 'image/png';
?>
<TileMap version="1.0.0" tilemapservice="<?php echo $baseUrl.$basename ?>">
	<Title><?php echo htmlspecialchars($title) ?></Title>
	<Abstract><?php echo htmlspecialchars($description) ?></Abstract>
	<SRS><?php echo $srs ?></SRS>
	<BoundingBox minx="<?php echo $bounds[0] ?>" miny="<?php echo $bounds[1] ?>" maxx="<?php echo $bounds[2] ?>" maxy="<?php echo $bounds[3] ?>" />
	<Origin x="<?php echo $originx ?>" y="<?php echo $originy ?>"/>
	<TileFormat width="256" height="256" mime-type="<?php echo $mime ?>" extension="<?php echo $format ?>"/>
	<TileSets profile="global-<?php echo $profile ?>">
<?php for ($zoom = $m['minzoom']; $zoom < $m['maxzoom']+1; $zoom++ ) { ?>
		<TileSet href="<?php echo $baseUrl.$basename.'/'.$zoom ?>" units-per-pixel="<?php echo $initialResolution / pow(2, $zoom) ?>" order="<?php echo $zoom ?>" />
<?php } ?>
	</TileSets>
</TileMap>
  <?php 
endif; ?>