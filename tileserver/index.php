<?php
require "tileserver.php";
$maps = maps();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <title><?php echo $config['serverTitle'] ?></title>
  <!-- POWERED BY TILESERVER.PHP -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"/>
  <meta http-equiv="X-UA-Compatible" content="chrome=1">
  <meta http-equiv="X-UA-Compatible" content="IE=8">
  <!--[if lte IE 6]>
  <meta http-equiv="refresh" content="0; url=http://www.ie6countdown.com/" />
  <script type="text/javascript">window.top.location = 'http://www.ie6countdown.com/';</script>
  <![endif]-->
  <link rel="stylesheet" href="css/style.css" type="text/css" media="screen">
  <script src="js/OpenLayers.mobile.wmts.js"></script>
  <script>
// Get rid of address bar on iphone/ipod
var fixSize = function() {
    window.scrollTo(0,0);
    document.body.style.height = '100%';
    if (!(/(iphone|ipod)/.test(navigator.userAgent.toLowerCase()))) {
        if (document.body.parentNode) {
            document.body.parentNode.style.height = '100%';
        }
    }
};
setTimeout(fixSize, 700);
setTimeout(fixSize, 1500);

var activeView = "";
function toggle(view) {
	if (activeView !== "")
		document.getElementById(activeView).style.display = "none";
	if (activeView !== view) {
		document.getElementById(view).style.display = "block";
		activeView = view;
	} else {
		activeView = "";
	}
}
  </script>
</head>

<body>
<div id="content">
<div id="header">
  <h1>TileServer OpenSource Project v0.1: Demo</h1>
  <a href="" onClick="toggle('usage'); return false;">Usage details (APIs, WMTS, ArcGIS, etc)</a> |
  <a href="" onClick="toggle('message'); return false;">Message</a>
  <button id="button" class="cupid-green" onClick="toggle('gallery'); return false;">Other maps</button>
</div>
<div id="map"></div>
<div id="headershade"></div>
<div id="push"></div>
</div>

<div id="gallery" class="fade">
    <h2>Choose a map</h2>
    You can open one of the maps exposed with this TileServer service:
    <hr/>
    <?php 
	# Are there some maps on the server?
	if (count($maps) == 0) { ?>

		<h3>No maps available yet</h3>
	
		<?php
		# Print the available maps
		} else {
		  // print_r($maps);
		  echo "<h3>Available maps</h3>";
		  echo $metadata;	  
		 /*echo "<ul>";
		  foreach ($maps as $map) {
			      //One approach to figure out the center of the bounding box
		  		  $bounds = $map['bounds'];
		          //Convert the map bounds to XY Spherical Mercator
			      list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
			      list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
			      $bounds = array( $minx, $miny, $maxx, $maxy );
			      //Use meters figure out the center of the bounding box
			      $mx= $bounds[2]+(($bounds[0]-$bounds[2])/2);
			      $my= $bounds[3]+(($bounds[1]-$bounds[3])/2);
			      //Convert the bounding box back to LatLon
			      $LatLonOut = $mercator->MetersToLatLon($mx, $my);
				  $lat = $LatLonOut[0];
			      $lon = $LatLonOut[1];
				  $zoom = 15;
                  //Figure out the XY tile names
				  $xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
				  $ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom)); 
			      //echo "<li><a class=\"thumb\" href=\"".$map['basename']."\"style=\"background-image:url(".$baseUrl."".$map['basename']."/".$zoom."/".$xtile."/".$ytile.".png".");\">".$map['name']."</a>";	
				
		          //debug: uncomment to hard code a thumbnail tile		
		         //echo "<li><a class=\"thumb\" href=\"".$map['basename']."\"style=\"background-image:url(".$baseUrl."".$map['basename']."/".intval(16)."/".intval(16816)."/".intval(24351).".png".");\">".$map['name']."</a>";	
		  	 	
		  }
		  echo "</ul>";*/
		
		}
		
		?>
	
    	<a class="thumb" href="chicago/googlemaps.html" style="background-image:url(http://localhost:8888/tileserverdemo/chicago/16/16816/24351.png);">chicago</a>
	    <a class="thumb" href="dogami/googlemaps.html" style="background-image:url(http://localhost:8888/tileserverdemo/dogami/15/5104/11681.png);">dogami</a>
        <a class="thumb" href="#mapbox.natural-earth-2" style="background-image:url(http://a.tiles.mapbox.com/v3/mapbox.natural-earth-2/thumb.png);">natural earth</a>
</div>

<div id="message" class="fade">
  <h2>TileServer.php successfully installed</h2>
  Now you should upload some maps into /sdfasfjas/fadfa/afsdf/asfasdf/asdfasdf/afasdf on your server.
  <p>Supported formats are: a directory with TMS or XYZ tileset or a file with extention .mbtiles.
  <p>You can easily prepare you maps with <a href="http://www.maptiler.org/">MapTiler</a> or <a href="http://mapbox.com/tilemill/">TileMill</a>.
</div>

<div id="usage" class="fade">
  <h2>Use this map in your website or desktop GIS software</h2>
  This website is directly optimized for mobile phones and tablets.<br/>
  The map can be easily used in other websites, mobile devices or traditional desktop applications.
  <h3>How to load this map in a desktop software:</h3>
  <a href="#">Google Earth</a> | <a href="demo/qgis/qgis.html">QuantumGIS (qgis) 1.9+</a> | <a href="demo/udig/udig.html">UDig</a> | <a href="demo/abt/abt.html">ESRI ArcGIS Desktop 9.3+</a> | <a href="demo/arcgis10/arcgis.html">ESRI ArcGIS Desktop 10.1+</a>
  <h3>Use this map with web mapping API:</h3>
  <a href="chicago/googlemaps.html">Google Maps V3</a> | <a href="demo/openlayersxyz.html">OpenLayers XYZ</a> | <a href="demo/openlayerswmts.html">OpenLayers WMTS</a> | <a href="demo/leafletxyz.html">Leaflet</a> | <a href="demo/mapboxjsxyz.html">Mapbox</a>
  <h3>Or standardized protocols:</h3>
  <a href="#">WMTS</a> | <a href="#">TMS</a> | <a href="#">QuadKey</a> | <a href="#">KML</a>
  <p>
</div>

<a href="http://www.github.com/klokantech/tileserver.php/">TileServer.php</a> project by
<a href="http://www.klokantech.com/">Klokan Technologies</a>
<script type="text/javascript">
var urls = [
    "http://otile1.mqcdn.com/tiles/1.0.0/osm/${z}/${x}/${y}.png",
    "http://otile2.mqcdn.com/tiles/1.0.0/osm/${z}/${x}/${y}.png",
    "http://otile3.mqcdn.com/tiles/1.0.0/osm/${z}/${x}/${y}.png",
    "http://otile4.mqcdn.com/tiles/1.0.0/osm/${z}/${x}/${y}.png"
];
var map = new OpenLayers.Map({
    div: "map",
    theme: null,
    layers: [
        new OpenLayers.Layer.XYZ("OSM (with buffer)", urls, {
            transitionEffect: "resize", buffer: 1, sphericalMercator: true,
            attribution: "Data CC-By-SA by <a href='http://openstreetmap.org/'>OpenStreetMap</a>"
        }) 
    ],
    controls: [
        new OpenLayers.Control.TouchNavigation({
            dragPanOptions: {
                enableKinetic: true
            }
        }),
        new OpenLayers.Control.Zoom(),
        new OpenLayers.Control.Attribution()
    ],
    center: [0, 3000000],
    zoom: 3
});
//map.zoomToMaxExtent();
</script>
</body>
</html>