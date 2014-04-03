<?php
/*
 * TileServer.php project
 * ======================
 * https://github.com/klokantech/tileserver-php/
 * Copyright (C) 2012 - Klokan Technologies GmbH
 */

# Set you own config values here:
$config = array(
  "baseUrls" => array("http://localhost/"),
  "serverTitle" => "TileServer.php v0.1",
);

# PHP debugging
ini_set("error_reporting", "true");
error_reporting(E_ALL|E_STRCT);

# Global variable + ccepted GET / POST variables from the outside world
$baseUrl = $config['baseUrls'][0];
// TODO: We can detext the baseUrl as well - for defined requests

$service = (array_key_exists('service', $_GET)) ? $_GET['service'] : "";
$layer = (array_key_exists('layer', $_GET)) ? $_GET['layer'] : "";
$callback = (array_key_exists('callback', $_GET)) ? $_GET['callback'] : "";

# CORS header
header('Access-Control-Allow-Origin: *');

# ------------
# TEST SERVICE
# ------------
if ($service == 'test') {
  header("Content-Type: text/plain; charset=utf-8");
  echo "TileServer.php (", $config['serverTitle'], ') at ', $baseUrl;
  die();
}

# ------------
# HTML SERVICE
# ------------
if ($service == 'html'):
  $maps = maps();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"/>
  <title><?php echo $config['serverTitle'] ?></title>
  <link rel="stylesheet" type="text/css" href="http://maptilercdn.s3.amazonaws.com/tileserver.css" />
  <script src="http://maptilercdn.s3.amazonaws.com/tileserver.js"></script>
<body>
<script>tileserver(null,'<?php echo $baseUrl ?>tms','<?php echo $baseUrl ?>wmts');</script>
<h1>Welcome to <?php echo $config['serverTitle'] ?></h1>
<p>
This server distributes maps to desktop, web, and mobile applications.
</p>
<p>
The mapping data are available as OpenGIS Web Map Tiling Service (OGC WMTS), OSGEO Tile Map Service (TMS), and popular XYZ urls described with TileJSON metadata.
</p>
<?php

# Test if the config value for baseUrls is set correctly
if ((strpos($baseUrl, $_SERVER['HTTP_HOST']) === false) or ($baseUrl[strlen($baseUrl)-1] !== '/')) {
  // TODO: Make a test with ?service=test and suggest the value - or is there any way how to get URL for actual directory with mod_rewrite in use ?
?>
<h3 style="color:darkred;">Wrong configuration of the "BaseURL" in tileserver.php</h3>
<p style="color:darkred; font-style: italic;">
Please modify 'tileserver.php' file and replace the '<?php echo $baseUrl ?>' in the $config variable with '<?php echo selfUrl(); ?>' (with slash in the end) or other correct address to your server. Multiple CNAME can be used for better performance - more in <a href="readme.md">readme.txt</a>.
</p>
<?php }
# Are there some maps on the server?
if (count($maps) == 0) { ?>

<h3 style="color:darkred;">No maps available yet</h3>
<p style="color:darkred; font-style: italic;">
Ready to go - just upload some maps into directory: <?php echo getcwd(); ?>/ on this server.
</p>
<p>
Note: The maps can be a directory with tiles in XYZ format with metadata.json file.<br/>
You can easily convert existing geodata (GeoTIFF, ECW, MrSID, etc) to this tile structure with <a href="http://www.maptiler.com">MapTiler Cluster</a> or open-source projects such as <a href="http://www.klokan.cz/projects/gdal2tiles/">GDAL2Tiles</a> or <a href="http://www.maptiler.org/">MapTiler</a> or simply upload any maps in MBTiles format made by <a href="http://www.tilemill.com/">TileMill</a>. Helpful is also the <a href="https://github.com/mapbox/mbutil">mbutil</a> tool. Serving directly from .mbtiles files is supported, but with decreased performance.
</p>

<?php
# Print the available maps
} else {
  // print_r($maps);
  echo "<h3>Available maps</h3>";
  echo "<ul>";
  foreach ($maps as $map) {
    // echo "<li><a href=\"".$map['basename']."\">".$map['name']."</a>" ;
    echo "<li>".$map['name'];
  }
  echo "</ul>";
}
?>

</body>
</html>

<?php
die;
endif;

# ------------
# JSON SERVICE
# ------------
if ($service == 'json'):
  header("Content-Type:application/json; charset=utf-8");
  
  if ($layer && $layer != 'tileserver') {
    $output = metadataTileJson(layer($layer));
  } else {
    $maps = maps();
    $tilejsons = array();
    foreach ($maps as $map)
      $tilejsons[] = metadataTileJson($map);
    $output = $tilejsons;
  }
  $output = json_encode($output);
  $output = str_replace("\\/","/",$output); 
  if ($callback) echo "$callback($output);";
  else echo $output;
die;
endif;


# INTERNAL FUNCTIONS:

function maps() {
  $maps = array();
  # Scan all directories with metadata.json
  $mjs = glob('*/metadata.json');
  if ($mjs) foreach ($mjs as $mj) $maps[] = metadataFromMetadataJson($mj); 
  # Scan all mbtiles
  $mbts = glob('*.mbtiles');
  if ($mbts) foreach ($mbts as $mbt) $maps[] = metadataFromMbtiles($mbt);
  return $maps;
}

function layer( $layer ) {
  if (strpos($layer, '.mbtiles') === false)
    return metadataFromMetadataJson($layer.'/metadata.json');
  else
    return metadataFromMbtiles($layer);
}

function metadataFromMetadataJson( $jsonFileName ) {
  $metadata = json_decode( file_get_contents($jsonFileName), true );
	$metadata = metadataValidation($metadata);
	$metadata['basename'] = str_replace('/metadata.json', '', $jsonFileName);
	return $metadata;
}

function metadataFromMbtiles( $mbt ) {
  $metadata = array();
  $db = new PDO('sqlite:'.$mbt,'','',array(PDO::ATTR_PERSISTENT => true));
  if (isset($db)) {
    $result = $db->query('select * from metadata');
		$resultdata = $result->fetchAll();
		foreach ($resultdata as $r) {
		    $metadata[$r['name']] = $r['value'];
		}
  	$metadata = metadataValidation($metadata);
  	$metadata['basename'] = $mbt;
  }
	return $metadata;
}

function metadataValidation( $metadata ) {
	if (array_key_exists('bounds', $metadata )) {
  	// TODO: Calculate bounds from tiles if bounds is missing - with GlobalMercator
		$metadata['bounds'] = array_map( 'floatval', explode(',', $metadata['bounds'] ));
	}
	if (!array_key_exists('profile', $metadata )) {
		$metadata['profile'] = 'mercator';
	}	
	// TODO: detect format, minzoom, maxzoom, thumb
	// scandir() for directory / SQL for mbtiles
	if (array_key_exists('minzoom', $metadata ))
		$metadata['minzoom'] = intval( $metadata['minzoom'] );
	else
		$metadata['minzoom'] = 0;
	if (array_key_exists('maxzoom', $metadata ))
		$metadata['maxzoom'] = intval( $metadata['maxzoom'] );
	else
		$metadata['maxzoom'] = 18;
	if (!array_key_exists('format', $metadata )) {
		$metadata['format'] = 'png';
	}
	/*
	if (!array_key_exists('thumb', $metadata )) {
		$metadata['profile'] = 'mercator';
	}
	*/
	return $metadata;
}

function metadataTileJson( $metadata ) {
  global $config;
  $metadata['tilejson'] = '2.0.0';
  $metadata['scheme'] = 'xyz';
  $tiles = array();
  foreach($config['baseUrls'] as $url)
    $tiles[] = $url.$metadata['basename'].'/{z}/{x}/{y}.'.$metadata['format'];
  #print_r($tiles);
  $metadata['tiles'] = $tiles;
  return $metadata;
}

function selfUrl( $serverOnly = false ) {
    if(!isset($_SERVER['REQUEST_URI'])){
        $serverrequri = $_SERVER['PHP_SELF'];
    }else{
        $serverrequri = $_SERVER['REQUEST_URI'];
    }
    $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : "";
    $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
    if ($serverOnly) return 'http'.$s.'://'.$_SERVER['SERVER_NAME'].$port."/";
    return 'http'.$s.'://'.$_SERVER['SERVER_NAME'].$port.$serverrequri;
}

function doConditionalGet($timestamp) {
    $last_modified = gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    $etag = '"'.md5($last_modified).'"';
    // Send the headers
    header("Last-Modified: $last_modified");
    header("ETag: $etag");
    // See if the client has provided the required headers
    $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ?
        stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']) :
        false;
    $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ?
        stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : 
        false;
    if (!$if_modified_since && !$if_none_match) {
        return;
    }
    // At least one of the headers is there - check them
    if ($if_none_match && $if_none_match != $etag) {
        return; // etag is there but doesn't match
    }
    if ($if_modified_since && $if_modified_since != $last_modified) {
        return; // if-modified-since is there but doesn't match
    }
    // Nothing has changed since their last request - serve a 304 and exit
    header('HTTP/1.0 304 Not Modified');
    exit;
}

/*
TODO: https://github.com/klokantech/tileserver-php/issues/2

function geoToMercTile($lon, $lat, $zoom) {
	$xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
	$ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));
	return array($xtile, $ytile);
}

function geoToGeoTile($lon, $lat, $zoom) {
	$res = 180 / 256.0 / pow(2, $zoom);
	$xtile = floor( ceil( (180 + $lat) / $res / 256.0 ) - 1 );
	$ytile = floor( ceil( (90 + $lon) / $res / 256.0 ) - 1 );
	return array($xtile, $ytile);
}

function tileRange($bounds, $minzoom, $maxzoom) {
	for ($z=$minzoom; $z < $maxzoom+1; $z++) {
		print "$z\n";
		list($minx, $miny) = geoToMercTile($bounds[0], $bounds[1], $z);
		list($maxx, $maxy) = geoToMercTile($bounds[2], $bounds[3], $z);
		print_r( array($minx, $miny, $maxx, $maxy) );
	}
}

// Better use the port of Klokans' GlobalMapTiles:
*/

/*
	GlobalMapTiles - part of Aggregate Map Tools
	Version 1.0
	Copyright (c) 2009 The Bivings Group
	All rights reserved.
	Author: John Bafford
	
	http://www.bivings.com/
	http://bafford.com/softare/aggregate-map-tools/
	
	Based on GDAL2Tiles / globalmaptiles.py
	Original python version Copyright (c) 2008 Klokan Petr Pridal. All rights reserved.
	http://www.klokan.cz/projects/gdal2tiles/
	
	Permission is hereby granted, free of charge, to any person obtaining a
	copy of this software and associated documentation files (the "Software"),
	to deal in the Software without restriction, including without limitation
	the rights to use, copy, modify, merge, publish, distribute, sublicense,
	and/or sell copies of the Software, and to permit persons to whom the
	Software is furnished to do so, subject to the following conditions:
	
	The above copyright notice and this permission notice shall be included
	in all copies or substantial portions of the Software.
	
	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
	OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
	THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
	FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
	DEALINGS IN THE SOFTWARE.
*/

class GlobalMercator
{
	var $tileSize;
	var $initialResolution;
	var $originShift;
	
	//Initialize the TMS Global Mercator pyramid
	function __construct($tileSize = 256)
	{
		$this->tileSize = $tileSize;
		$this->initialResolution = 2 * M_PI * 6378137 / $this->tileSize;
		# 156543.03392804062 for tileSize 256 Pixels
		$this->originShift = 2 * M_PI * 6378137 / 2.0;
		# 20037508.342789244
	}
	
	//Converts given lat/lon in WGS84 Datum to XY in Spherical Mercator EPSG:900913
	function LatLonToMeters($lat, $lon)
	{
		$mx = $lon * $this->originShift / 180.0;
		$my = log( tan((90 + $lat) * M_PI / 360.0 )) / (M_PI / 180.0);
	
		$my *= $this->originShift / 180.0;
		
		return array($mx, $my);
	}
	
	//Converts XY point from Spherical Mercator EPSG:900913 to lat/lon in WGS84 Datum
	function MetersToLatLon($mx, $my)
	{
		$lon = ($mx / $this->originShift) * 180.0;
		$lat = ($my / $this->originShift) * 180.0;
	
		$lat = 180 / M_PI * (2 * atan( exp( $lat * M_PI / 180.0)) - M_PI / 2.0);
		
		return array($lat, $lon);
	}
	
	//Converts pixel coordinates in given zoom level of pyramid to EPSG:900913
	function PixelsToMeters($px, $py, $zoom)
	{
		$res = $this->Resolution($zoom);
		$mx = $px * $res - $this->originShift;
		$my = $py * $res - $this->originShift;
		
		return array($mx, $my);
	}
	
	//Converts EPSG:900913 to pyramid pixel coordinates in given zoom level
	function MetersToPixels($mx, $my, $zoom)
	{
		$res = $this->Resolution( $zoom );
		
		$px = ($mx + $this->originShift) / $res;
		$py = ($my + $this->originShift) / $res;
		
		return array($px, $py);
	}

	//Returns a tile covering region in given pixel coordinates
	function PixelsToTile($px, $py)
	{
		$tx = ceil( $px / $this->tileSize ) - 1;
		$ty = ceil( $py / $this->tileSize ) - 1;
		
		return array($tx, $ty);
	}
	
	//Returns tile for given mercator coordinates
	function MetersToTile($mx, $my, $zoom)
	{
		list($px, $py) = $this->MetersToPixels($mx, $my, $zoom);
		
		return $this->PixelsToTile($px, $py);
	}
	
	//Returns bounds of the given tile in EPSG:900913 coordinates
	function TileBounds($tx, $ty, $zoom)
	{
		list($minx, $miny) = $this->PixelsToMeters( $tx*$this->tileSize, $ty*$this->tileSize, $zoom );
		list($maxx, $maxy) = $this->PixelsToMeters( ($tx+1)*$this->tileSize, ($ty+1)*$this->tileSize, $zoom );
		
		return array($minx, $miny, $maxx, $maxy);
	}
	
	//Returns bounds of the given tile in latutude/longitude using WGS84 datum
	function TileLatLonBounds($tx, $ty, $zoom)
	{
		$bounds = $this->TileBounds($tx, $ty, $zoom);
		
		list($minLat, $minLon) = $this->MetersToLatLon($bounds[0], $bounds[1]);
		list($maxLat, $maxLon) = $this->MetersToLatLon($bounds[2], $bounds[3]);
		 
		return array($minLat, $minLon, $maxLat, $maxLon);
	}
	
	//Resolution (meters/pixel) for given zoom level (measured at Equator)
	function Resolution($zoom)
	{
		return $this->initialResolution / (1 << $zoom);
	}
}
$mercator = new GlobalMercator();
?>
