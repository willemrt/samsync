<?php
///////////////////////////////////////////////////////////
//////// Setup the standalone wordpress thingo:
define('WP_USE_THEMES', false);
$wp_path  = '/home/148034.cloudwaysapps.com/kwybgsrpfc/public_html/';
require( $wp_path.'wp-blog-header.php' );
// Need to require these files
if ( !function_exists('media_handle_upload') ) {
	require_once($wp_path. "wp-admin" . '/includes/image.php');
	require_once($wp_path. "wp-admin" . '/includes/file.php');
	require_once($wp_path. "wp-admin" . '/includes/media.php');
}
//////////////////////////////////////////////////////////

//////////////////////////////////////////////////////////
// CONFIG

// Where should we store the images for products?
// This is a temporary storage location, so we can then
// add them to Wordpress properly.
$image_path = "images/";
$guid = "30599bd7-2e3f-6e44-545c-ed9d2461670e";

// general settings:
$replace_images = true ; // this smashes the filesystem if we do it every time
$wipe_first = false; // if true, delete ALL products before import.

// The URL , with CentreID included.
$api_url = "https://sam.org.au/api/ws.asmx/RetrieveWebActive?strArtCentreGUID={$guid}";


// END CONFIG
//////////////////////////////////////////////////////////

 
 
// Get existing SKUs :
$ids = $wpdb->get_results("SELECT DISTINCT pm.meta_value,pm.post_id FROM wp_postmeta AS pm LEFT JOIN wp_posts AS p ON pm.post_id = p.ID
WHERE pm.meta_key = '_sku' AND p.post_status='publish' AND p.post_type='product' ", ARRAY_A);
$skus = array();
$pids = array();
foreach($ids as $prod) {
	$skus[] = $prod['meta_value'];
	$pids[] = $prod['post_id'];
	$sku_lookup[$prod['meta_value']] = $prod['post_id'];
}
$skus_pulled = array();

if($wipe_first) {
	foreach($pids as $pid) {
		wp_delete_post($pid,true);
	}	 
}

// Get product category terms:
$wp_cats = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
$cats = array();
// Make them more usable:
foreach($wp_cats as $cat) {
	$cats[$cat->name] = $cat;
}
// Get product category terms:
$wp_artists = get_terms(array('taxonomy'=>'artist','hide_empty'=>false));
$artists = array();
// Make them more usable:
foreach($wp_artists as $artist) {
	$artists[$artist->name] = $artist;
}
print_r($cats);

// Load it as XML:
$xml = simplexml_load_file($api_url);

// Decode the element as a JSON string 
// who writes an API that returns a single XML node
// full of JSON data? bat shit crazy.
$works = json_decode($xml);

// Process the works:
foreach($works as $work) {
	$thumb_id = '';
	$skus_pulled[] = $work->ArtworkId;
	if(in_array($work->ArtworkId,$skus)) {
		if ($work->ArtworkId != '81600947') {
			continue;
		}
		print "--------------------------------------\n";
		print_r($work);
		print "Already exists\n";
		print "{$work->ArtworkId} ---> {$work->Price}\n";
		// ONly process the image if there is an image:
		if($work->HasImage == 'Yes') {
			// Get the temp file path:
			$image_basename = "{$work->ArtworkId}.jpg";
			$image_filename = "{$image_path}{$image_basename}";
			$image_tmpfilename = "{$image_path}tmp-{$image_basename}";

			$post_id = $sku_lookup[$work->ArtworkId];
			$thumb_id = get_post_thumbnail_id($post_id);
			print "Thumb check: \n";
			var_dump($thumb_id);
			if($thumb_id == '') {
				// If the file doesn't exist or we are replacing all images:
				if(file_exists($image_filename)) {
					copy($image_filename,$image_tmpfilename);

					print "$image_filename created.";
					print "\n\n\n------------------------------------\n\n\n";
					$file_array = array(
						'name'=> $image_basename,
						'tmp_name' => $image_tmpfilename
					);

					$thumb_id = media_handle_sideload($file_array, $post_id, 'gallery desc');
					set_post_thumbnail($post_id, $thumb_id);
				}
				
			}


		}
	}
	else {
		$content = nl2br($work->StoryNarrative);
		$content_attributes = array('ArtistName','Received','Medium','ArtworkSize');
		$content .= '<dl class="artwork-attributes">';
		foreach($content_attributes as $attr) {
			if(!empty($work->$attr)) {
				$pretty_name = implode(' ',preg_split('/(?=[A-Z])/', $attr));
				$content .= "<dt>{$pretty_name}</dt><dd>{$work->$attr}</dd>";	
			}
		}
		$content .= '</dl>';
		$post_data = array(
			'post_title' => "{$work->StoryTitle} - {$work->ArtistName}",
			'post_excerpt'=> $content,
			'post_status'=>'publish',
			'post_type'=>'product'
		);
		$post_id = wp_insert_post($post_data);
	    wp_set_object_terms( $post_id, 'simple', 'product_type' );
	    update_post_meta( $post_id, '_visibility', 'visible' );
	    update_post_meta( $post_id, '_stock_status', 'instock');
	    update_post_meta( $post_id, '_manage_stock', 'yes');
	    update_post_meta( $post_id, '_stock', '1');
	    update_post_meta( $post_id, 'total_sales', '0' );
	    update_post_meta( $post_id, '_downloadable', 'no' );
	    update_post_meta( $post_id, '_virtual', 'no' );
	    update_post_meta( $post_id, '_price', intval($work->Price),2) ;
	    update_post_meta( $post_id, '_regular_price', intval($work->Price),2) ;
	    update_post_meta( $post_id, '_sale_price', '' );
	    update_post_meta( $post_id, '_purchase_note', '' );
	    update_post_meta( $post_id, '_featured', 'no' );
	    update_post_meta( $post_id, '_weight', '' );
	    update_post_meta( $post_id, '_length', '' );
	    update_post_meta( $post_id, '_width', '' );
	    update_post_meta( $post_id, '_height', '' );
	    update_post_meta( $post_id, '_sku', $work->ArtworkId );
	    update_post_meta( $post_id, '_product_attributes', array() );
	    update_post_meta( $post_id, '_sale_price_dates_from', '' );
	    update_post_meta( $post_id, '_sale_price_dates_to', '' );
	    update_post_meta( $post_id, '_sold_individually', '' );
	    update_post_meta( $post_id, '_backorders', 'no' );

	    // add the category:
	    $cat_term_id = false;
	    if(!isset($cats[$work->Category])) {
	    	$out = wp_insert_term(
	    		$work->Category,
	    		'product_cat',
	    		array(
	    			'description'=>'',
	    			'slug'=>strtolower($work->Category)
	    		)
	    	);
	    	if(!is_wp_error($out)){
		    	$cat_term_id = $out[0];
		    	$cats[$work->Category] = get_term($cat_term_id, 'product_cat');
		    }
	    } else {
	    	$cat = $cats[$work->Category];
			$cat_term_id = $cat->term_id;
	    }
	    if($cat_term_id){
	    	print "CATEGORY: {$cat_term_id}\n\n";
			wp_set_object_terms($post_id, array($cat_term_id), 'product_cat');
	    }

	    // add the artist:
	    $artist_term_id = false;
	    if(!isset($artists[$work->ArtistName])) {
	    	$out = wp_insert_term(
	    		$work->ArtistName,
	    		'artist',
	    		array(
	    			'description'=>'',
	    			'slug'=>strtolower($work->ArtistName)
	    		)
	    	);
	    	if(!is_wp_error($out)){
		    	$artist_term_id = $out[0];
		    	$cats[$work->ArtistName] = get_term($artist_term_id, 'artist');
		    }
	    } else {
	    	$artist = $artists[$work->ArtistName];
			$artist_term_id = $artist->term_id;
	    }
	    if($artist_term_id){
	    	print "ARTIST: {$artist_term_id}\n\n";
			wp_set_object_terms($post_id, array($artist_term_id), 'artist');
	    }


		// ONly process the image if there is an image:
		if($work->HasImage == 'Yes') {
			// Get the temp file path:
			$image_basename = "{$work->ArtworkId}.jpg";
			$image_filename = "{$image_path}{$image_basename}";
			$image_tmpfilename = "{$image_path}tmp-{$image_basename}";
			// If the file doesn't exist or we are replacing all images:
			if(!file_exists($image_filename) || $replace_images ) {
				// Image api url:
				$image_api_url = "https://sam.org.au/api/ws.asmx/RetrieveArtworkImage?strArtCentreGUID={$guid}&intArtWorkId={$work->ArtworkId}&intAWSize=1000";
				$xml = simplexml_load_file($image_api_url);
				$jpeg = fopen($image_filename,'w');
				$jpeg_data = base64_decode($xml);
				fwrite($jpeg,  $jpeg_data);	
				copy($image_filename,$image_tmpfilename);

				print "$image_filename created.";
				print "\n\n\n------------------------------------\n\n\n";
				$file_array = array(
					'name'=> $image_basename,
					'tmp_name' => $image_tmpfilename
				);

				$thumb_id = media_handle_sideload($file_array, $post_id, 'gallery desc');
				set_post_thumbnail($post_id, $thumb_id);
			}
		}
	}
}


// Check for skus that were no longer present:
foreach($sku_lookup as $sku=>$post_id) {
	// if this sku wasn't pulled - then make the _stock level 0.
	if(!in_array($sku, $skus_pulled)) {
	    update_post_meta( $post_id, '_stock', '0');
	    print "SKU: {$sku} zeroed.\n";
	}
}

?>
