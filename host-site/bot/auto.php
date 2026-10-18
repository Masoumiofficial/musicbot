<?php
include('../wp-load.php');
include("config.php");
set_time_limit(0);

$arrContextOptions = array(
    "ssl" => array(
        "verify_peer" => false,
        "verify_peer_name" => false,
    ),
);

$rssFeedUrl = "https://www.mybia2music.com/feed";
$rssContent = @file_get_contents($rssFeedUrl, false, stream_context_create($arrContextOptions));

if ($rssContent === FALSE) {
    die("خطا در بارگذاری فید");
}

$rssXml = @simplexml_load_string($rssContent);
if ($rssXml === FALSE) {
    die("خطا در تجزیه فید XML");
}
$counter = 0;

foreach ($rssXml->channel->item as $item) {
    if (++$counter > 30) break;

    $postUrl = esc_url($item->link);
    $postTitle = esc_html($item->title);
    $existingLinks = $wpdb->get_col($wpdb->prepare("SELECT url FROM wp_subcheck WHERE url = %s", $postUrl));

    if (empty($existingLinks)) {
        $wpdb->insert('wp_subcheck', array('url' => $postUrl), array('%s'));
		
        include("script.php");
		
        echo ("<p><a href='" . $postUrl . "'>" . $postTitle . "</a></p>");
		if( $counter >= 1 ) break;
		$counter++;
    }
}
?>