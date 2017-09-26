<?php
require("./include_connect.php");
require_once('./dbscan.php');
ini_set('memory_limit', '256M');
error_reporting(E_ALL);


function jaccard_distance($text1, $text2){
	$text1_array = explode(',', $text1);
	$text1_size = count($text1_array);
	$text2_array = explode(',', $text2);
	$text2_size = count($text2_array);
	$text_total_array = array_unique(array_merge($text1_array,$text2_array));
	$text_total_size = count($text_total_array);
	$text_intersect = array_unique(array_intersect($text1_array, $text2_array));
	$text_intersect_size = count($text_intersect);
	$jaccard_distance_value = pow(1 - $text_intersect_size/$text_total_size,4)*120;
	return $jaccard_distance_value;
}

$date_int = date("Y-m-d");

$today_time = strtotime($date_int);
$yesterday = date("Y-m-d", $today_time-1*60*60*24);
$twodaysago = date("Y-m-d", $today_time-2*24*60*60);

$sql = "/* dbupdater15 */SELECT no, title, url, facebook_no from b_article_shared_1 WHERE date_int='$date_int' OR date_int='$yesterday' OR date_int='$todaysago'";
$query = mysql_query($sql, $connect) or die(mysql_error());
$articleid_array = array();
$title_array = array();
$keyword_array = array();
$url_array = array();
$facebook_array = array();
while($print = mysql_fetch_array($query)){
	$facebook_no_original = $print[3];
if($facebook_no_original > 1){

	$no = $print[0];
	$facebook_to_array = array($no => $facebook_no_original);
	$facebook_array = $facebook_array + $facebook_to_array;
	array_push($articleid_array, $no);
	$title = $print[1];
	$title = str_replace("사진","",$title);
	$title = str_replace("포토","",$title);
	$title = str_replace("속보","",$title);
	$title_to_array = array($no => $title);

	$title_array = $title_array + $title_to_array;
	$targeturl = $print[2];
	$url_to_array = array($no => $url);
	$url_array = array_merge($url_array, $url_to_array);

	$dic = '/var/www/html/mecab-ko-dic-2.0.0-20150517';
	ini_set('mecab.default_dicdir', $dic);
	$arg = array();
	$mecab = mecab_new($arg);
	$text = mecab_sparse_tostr($mecab, $title);
	$text = explode("\n", $text);
	$i=0;
	$n=0;
	while($i<count($text)){
		$print = $text[$i];
		$print = explode("\t", $print);
		$print1 = $print[0];
		$print2 = $print[1];
		$print2 = explode(",", $print2);
		array_unshift($print2, $print1);
		$type = substr($print2[1],0,1);
		// Add any words you want to exclude
		//$excludelist=array('');
		if($type=="N"||$type=="V"||$type=="M"){
			$n=$n+1;
			if(!in_array($print2[0], $excludelist)) {
			$texttosave = $texttosave.",".$print2[0];
			}
		}
		$i=$i+1;
	}
	$keyword_title = substr($texttosave, 1);
	$keyword_title_size = sizeof(explode(",",$keyword_title));
	$keyword_to_array = array($no => $keyword_title);
	$keyword_array = array_merge($keyword_array, $keyword_to_array);

	$keyword_title_size = NULL;
	$keyword_title = NULL;
	$texttosave = NULL;
}
}

$epsilon = 60;
$minpoints = 2;

$point_ids = $articleid_array;
$point_count = count($point_ids);
$distance_matrix = array();
for($i=0; $i<$point_count; $i++){ 
	$array_added = array();
	for($j=$i+1; $j<$point_count; $j++){
		$keyword_array_size_i = sizeof(explode(',',$keyword_array[$i]));
		$keyword_array_size_j = sizeof(explode(',',$keyword_array[$j]));
		$keyword_array_size_min = min($keyword_array_size_i, $keyword_array_size_j);
		if($keyword_array_size_min<5){
			$distance = 120;
		}else{
			$distance = jaccard_distance($keyword_array[$i], $keyword_array[$j]);
		}
		$array_to_add = array($point_ids[$j] => $distance);
		$array_added = $array_added + $array_to_add;
		if($distance < $epsilon){
		echo $distance;

		echo "&nbsp;&nbsp;&nbsp;&nbsp;";
		echo $keyword_array[$i];
		echo "&nbsp;&nbsp;&nbsp;&nbsp;";
		echo $keyword_array[$j];
		echo "<br>";
		}
	}
	$distance_matrix_to_add = array($point_ids[$i] => $array_added);
	$distance_matrix = $distance_matrix + $distance_matrix_to_add;
}


$final_cluster = array();

// Setup DBSCAN with distance matrix and unique point IDs
$DBSCAN = new DBSCAN($distance_matrix, $point_ids);
// Perform DBSCAN clustering
$clusters = $DBSCAN->dbscan($epsilon, $minpoints);
// Output results
$largest_cluster_size = 0;
foreach ($clusters as $index => $cluster){
	if (sizeof($cluster) > 0){
		foreach ($cluster as $member_point_id){
			if(count($cluster)<31 && count($cluster)>2){
				$cluster_to_add = $cluster_to_add.",".$member_point_id;
			}
		}
	$cluster_to_add = substr($cluster_to_add, 1);
	array_push($final_cluster, $cluster_to_add);
	$cluster_to_add = NULL;
	}
}

$epsilon = $epsilon-20;
foreach ($clusters as $index => $cluster){
	if (sizeof($cluster) > 30){
		$DBSCAN->set_points($cluster);
		$sub_clusters = $DBSCAN->dbscan($epsilon, $minpoints);
		foreach ($sub_clusters as $sub_cluster){
			foreach ($sub_cluster as $sub_cluster_point_id){
				if(count($sub_cluster)>2){
					$cluster_to_add = $cluster_to_add.",".$sub_cluster_point_id;
					echo '<li>'.$title_array[$sub_cluster_point_id].'&nbsp&nbsp&nbsp&nbsp'.$sub_cluster_point_id.'&nbsp;&nbsp;&nbsp;&nbsp;'.$facebook_array[$sub_cluster_point_id].'</li>';
				}
			}
			$cluster_to_add = substr($cluster_to_add, 1);
			array_push($final_cluster, $cluster_to_add);
			$cluster_to_add = NULL;
		}
	}
}
$final_cluster = array_filter($final_cluster);
$final_cluster = array_values($final_cluster);

$final_cluster_fb = array();
foreach($final_cluster as $array_text){
	$article_array = explode(',', $array_text);
	foreach($article_array as $article_array_now){
		$cluster_fb = $cluster_fb + $facebook_array[$article_array_now];
	}
	array_push($final_cluster_fb, $cluster_fb);
	$cluster_fb = NULL;
}


$howmany_clusters = sizeof($final_cluster);

for($i=0; $i<$howmany_clusters;$i++){
	$cluster_array = explode(",", $final_cluster[$i]);
	$earliest_article = min($cluster_array);

	$latest_article = max($cluster_array);
	$sql_date = "SELECT date_int, date_mktime, title from b_article_shared_1 WHERE no='$latest_article'";
	$result_date = mysql_query($sql_date, $connect) or die(mysql_error());
	$fetch_date = mysql_fetch_array($result_date);
	$cluster_date = $fetch_date[0];
	$cluster_mktime = $fetch_date[1];
	$cluster_title = $fetch_date[2];

	$sql_image = "SELECT rep_image from b_article_shared_1 WHERE no IN ($final_cluster[$i]) ORDER BY no desc";
	$result_image = mysql_query($sql_image, $connect) or die(mysql_error());
	while($print_image = mysql_fetch_array($result_image)){
		$rep_image = $print_image[0];
		if($rep_image!=''){
			break;
		}
	}

	//insert or update the cluster
	$sql = "INSERT INTO c_cluster (dateint, facebook_total, articlelist, articlelist_order, latest_article, earliest_article, title, rep_image) VALUES ('$cluster_date', '$final_cluster_fb[$i]', '$final_cluster[$i]', '$i', '$latest_article', '$earliest_article', '$cluster_title','$rep_image') ON DUPLICATE KEY UPDATE title='$cluster_title', latest_article='$latest_article', facebook_total='$final_cluster_fb[$i]', articlelist='$final_cluster[$i]', mktime='$cluster_mktime', earliest_article='$earliest_article', rep_image='$rep_image'";
	$query = mysql_query($sql, $connect);
}
?>