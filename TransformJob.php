<?php

/**
 * This script scans an html file specified by the $url varialbe for html link elements; 
   Checks the http HEAD for the link, and if the contenttype header parameter contains 'xml',
   Gets the file, and does some simple introspection to identify the metadata dialect in the content, 
   If a know dialect is recognized, transforms the file to ISO19139 XML using an xslt for that dialect, 
   The ISO1939 result is placed in a location on the server running this php script.
   The location is specified by the $thedir variable.
   
  The work is done by the parse_dir function. 
  
  The specified URL location will be scanned recursively-- if one of the links points at an html
  file, the parse_dir function is called recursively to examine that file for links. the final token 
  (after the last '/') is used to generate a subdirectory to hold any transformed metadata from that 
  link.
  
  This routine is designed for scanning sitemaps, sitemap indexes, or web accessible folders conataining
  metadata intended for harvesting.
	
	SMR 2018-06-01 Version 1.0
 */

# transform files from various metadata dialects to ISO19139 
# transforms are loaded from the USGIN organization metadataTransforms gitHub repository
$DataCitetoISOXslfile = file_get_contents("https://raw.githubusercontent.com/usgin/metadataTransforms/master/dataciteToISO19139v3.2.xslt");
$DublinCoretoISOXslfile = file_get_contents("https://raw.githubusercontent.com/usgin/metadataTransforms/master/qualifiedDCToISO19139v1.0.xslt");
$EMLtoISOXslfile=file_get_contents("https://raw.githubusercontent.com/usgin/metadataTransforms/master/eml2iso19139.xsl"); 
#eml transform has not been tested!
$CSDGMtoISOXslfile=file_get_contents("https://raw.githubusercontent.com/usgin/metadataTransforms/master/csdgm2iso19115_usgin3.0.xslt");

# set up the transform before entering loop so don't have to read the file each time.
$xslt = new XSLTProcessor();

function parse_dir($target,$url,$base_url){
	
	global $xslt, $DataCitetoISOXslfile, $DublinCoretoISOXslfile, $EMLtoISOXslfile, $CSDGMtoISOXslfile, $filecount;
	# target is the directory where result files will be written
	# url is the url for the file on the web to scan for links
	# $base_url is the base url that will be used to resolve relative links found in the file at URL.
	
	
	#beware of odd behavior using global xslt processor in recursive calls.... 
	
	# Use the Curl extension to query the url 
	$ch = curl_init();
	$timeout = 5;
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	$html = curl_exec($ch);
	curl_close($ch);

	# Create a DOM parser object
	$dom = new DOMDocument();

	#get the last segment of the URL, this will be a new directory in the output directory
	
	if (substr(trim($url), -1)=='/'){
		$url=substr($url,0,strlen($url)-1);
	}
	$urltokens = explode('/', $url);
	#$dirname = $urltokens[count($urltokens)-1];
	$host=$urltokens[0]."//".$urltokens[2];
	echo "host: ".$host."<br/>";
	
	# Load the content at the URL as html.
	# The @ before the method call suppresses any warnings that
	# loadHTML might throw because of invalid HTML in the page.
	@$dom->loadHTML($html);
	

	#echo "dirname: ".$dirname."<br/>";
	#echo "location: ".$location."<br/>";

	
	$filecount = 0;

	# Iterate over all the <a> tags
	foreach($dom->getElementsByTagName('a') as $link) {
		# Show the <a href>
		$thehref = $link->getAttribute('href');
		
		if (strpos($url,$thehref)){
			echo $thehref." is link to ancestor directory"."<br/>";
			continue;			
		}
		
		$service = $host.$thehref;
		#echo "host: ". $host . ", href: ".$thehref."<br/>";

		# this is an html call-- slows things
		$headers = get_headers($service, 1);
		if (strpos($headers[0],'HTTP/1.1 200') ===  false) {
			echo $service." did not return a valid response"."<br/>";
			continue;
		}
		
		$contenttype=$headers["Content-Type"];
		
		#echo "Service: ".$service."<br/>";
		#echo '<pre>'; print_r($headers); 
		#echo '</pre>'; */
		#echo "Content type: ".$contenttype;
		echo "<br/>"; 
	
		#$thetokens=explode('/', $thehref);
		if (substr(trim($thehref), -1)=='/'){
			$thehref=substr($thehref,0,strlen($thehref)-1);
		}
		$urltokens = explode('/', $thehref);
		#echo '<pre>'; print_r($urltokens); 
		#echo '</pre>';
		
		if (!strpos($contenttype,'xml')){
			echo $thehref." is not an xml file";
			echo "<br />";

			#get the last segment of the URL, this will be a new directory in the output directory

			$dirname = $urltokens[count($urltokens)-1];
			echo "dirname: ". $dirname ."<br/>";

			
			$thedir = $target.$dirname."/";
			if (!is_dir($thedir)){
						echo "mkdir: ".$thedir."<br/>";
						mkdir($thedir);
					}

			echo "call pars_dir: " . $thedir . ", " .$service. "<br/>";
			parse_dir( $thedir,$service,$base_url );

		} else {
			 #set up the file name for the ISO output
			$thetoken=$urltokens[count($urltokens)-1]; #get the last segment of the href
			$thetoken = str_replace("%3A","-",$thetoken);  #change URL encoded ':' characters to '-'
			if (substr($thetoken,-3,3)=='xml'){
				$thetoken=str_replace(".xml","-iso.xml",$thetoken);
			} else {
				$thetoken=$thetoken."-iso.xml";	
			}
			$my_file = $target.$thetoken;
			#echo $my_file." next file <br/>";
				
			#check if the output file is already in the target directory;
			# code won't overwrite existing files with this check	
			if (file_exists($my_file)){
					echo $my_file." already processed";
					echo "<br/>";
					continue;
				}
			
			if (strpos($headers[0],'HTTP/1.1 200') !==  false) {
				try {
					echo "processing <a href='".$service."'>".$thetoken."</a>";
					echo "<br />";
					$content = file_get_contents($service);
					
					#figure out which transform to use
					$teststring=substr($content,0,500); #take the first 500 characters
					# these tests are pretty rudimentary.... let's see if they're good enough.
					if (strpos($teststring,"MD_Metadata")){
						echo $service." is already ISO19139 <br/>";
						continue;
					} elseif (strpos($teststring,"MI_Metadata")) {
						echo $service." is ISO19139-2 <br/>";
						continue;
					} elseif (strpos($teststring,"eml")) {
						echo $service." is eml <br/>";
						$xslt->importStylesheet(new SimpleXMLElement($EMLtoISOXslfile));
					}  elseif (strpos($teststring,"idinfo")) {
						echo $service." is CSDGM <br/>";
						$xslt->importStylesheet(new SimpleXMLElement($CSDGMtoISOXslfile));
					} elseif (strpos($teststring,"datacite.org/schema")) {
						echo $service." is DataCite xml <br/>";
						$xslt->importStylesheet(new SimpleXMLElement($CSDGMtoISOXslfile));
					} elseif (strpos($teststring,"www.openarchives.org/OAI/2.0")) {
						echo $service." is OAI Dublin core <br/>";
						$xslt->importStylesheet(new SimpleXMLElement($DublinCoretoISOXslfile));
					} elseif (strpos($teststring,"csw:record")) {
						echo $service." is CSW record Dublin core <br/>";
						$xslt->importStylesheet(new SimpleXMLElement($DublinCoretoISOXslfile));
					} elseif (strpos($teststring,"rdf:Description")) {
						if (strpos($content,"dc:title")){
							echo $service." is RDF:Descriptions wrapped Dublin core <br/>";
							$xslt->importStylesheet(new SimpleXMLElement($DublinCoretoISOXslfile));
						} else {
							echo $service." has rdf:Description, no dc:title <br/>";
						}
					} else {
						echo $service." has an unrecognized metadata format <br/>";
						continue;
					}
					
					
					$newxml = $xslt->transformToXml(new SimpleXMLElement($content));
					# echo $newxml;
					if (!is_dir($target)){
						echo "mkdir: ".$target."<br/>";
						mkdir($target);
					}
					
					
					$handle = fopen($my_file, 'w') or die('Cannot open file:  '.$my_file); //implicitly creates file
					fwrite($handle, $newxml);
					fclose($handle);
					$filecount = $filecount + 1;
					echo "<br/>";
					// break;
				} catch (Exception $message) {
					echo 'Caught exception: ',  $message->getMessage(), "\n";
				}
				
			} else {
				echo "Invalid URL, please try again.";
				echo "<br />";
			}
		}
	}
}

# code based on example from http://htmlparsing.com/php.html
# this is the location of the root directory that contains xml metadata records
# or other subdirectories

#$base_url="http://hydro10.sdsc.edu";
$base_url="http://132.249.238.169:8080";
#$url = "http://hydro10.sdsc.edu/metadata/Wyoming_GeoLibrary/";
$url = "http://132.249.238.169:8080/metadata/cn.dataone.org/";
$thedir="./ISOfiles/cn.dataone.org/";

if (!is_dir($thedir)){
	mkdir($thedir);
}
parse_dir( $thedir,$url,$base_url );

echo "hey, I finished! ".$filecount." files processed";

?>

