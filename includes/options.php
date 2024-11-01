<?php

/**
 * Adds an entry to the admin menu
 * @version 0.1
 */
function sonder_add_admin_menu() {
    add_menu_page('Sonder settings', 'Sonder', 'manage_options', 'sonder', 'sonder_options_page');
}
add_action('admin_menu', 'sonder_add_admin_menu');

/**
 * Creates sections and fields for the options page
 * @version 0.1
 */
function sonder_settings_init() {
    register_setting('pluginPage', 'sonder_settings');
    add_settings_section('sonder_pluginPage_section_general', __('', 'sonder'), 'sonder_settings_section_general', 'pluginPage');
    add_settings_field('sonder_select_field_publish', __('Sonder checkbox default state', 'sonder'), 'sonder_select_field_publish_render', 'pluginPage', 'sonder_pluginPage_section_general');
    add_settings_field('sonder_text_token_field', __('Sonder token', 'sonder'), 'sonder_text_token_field_render', 'pluginPage', 'sonder_pluginPage_section_general');
}
add_action('admin_init', 'sonder_settings_init');

/**
 * Renders the publish field markup
 * @version 0.1
 */
function sonder_select_field_publish_render(){
    $options = get_option('sonder_settings');
    $sonderPublish = isset($options['sonder_publish']) ? $options['sonder_publish'] : 1;
?>
  <select name='sonder_settings[sonder_publish]'>
	   <option value='1' <?php selected($sonderPublish, 1);?>>Have "Publish to Sonder" checked by default</option>
     <option value='2' <?php selected($sonderPublish, 2);?>>No, leave it unchecked please</option>
	</select>
<?php
}

/**
 * Renders the token field along with a dummy notice solution
 * @version 0.1
 */
function sonder_text_token_field_render()
{
    $options = get_option('sonder_settings');
?>
<input type="hidden" name="sonder_settings[saved_notice]" value="Sonder settings was saved">
<input type="text" name="sonder_settings[sonder_token]" value="<?=isset($options['sonder_token']) ? $options['sonder_token'] : ''  ?>"><br>
<small>Paste here the Token from your  <a target="_blank" href="http://sonder.news/user/profile">sonder.news account</a></small>
<?php
}

/**
 * Schedules a cron job that submit posts to sonder
 * @version 0.1
 */
if ( ! wp_next_scheduled( 'sonder_submit' ) ) {
  wp_schedule_event( time(), 'hourly', 'sonder_submit' );
}
add_action( 'sonder_submit', 'sonder_submit_callback' );

/**
 * Workflow
 * - Finds and separates new articles and existing articles to sonder
 * - Tries to submit to sonder and reports the outcome
 * - Marks the articles that was sent as submitted
 * @version 0.1
 */
function sonder_submit_callback() {
  $options = get_option('sonder_settings');
  $existingPosts = array();
  if (!isset($options['sonder_token'])) {
    sonder_cron_status('Sonder Token not found');
    return;
  }

  $argsNew = array(
    'post_status' => array( 'publish'),
    'meta_query'     => array(
        array(
            'key'       => 'sonder_publish',
            'value'     => 'yes',
            'compare'   => '='
        ),
        array(
            'key'       => 'sonder_publish_update',
            'value'     => 'yes',
            'compare'   => '='
        ),
        array(
            'key'       => 'sonder_sent',
            'compare'   => 'NOT EXISTS'
        )
    ),
  );

  $argsExisting = array(
    'post_status' => array( 'publish'),
    'meta_query'     => array(
        array(
            'key'       => 'sonder_publish',
            'value'     => 'yes',
            'compare'   => '='
        ),
        array(
            'key'       => 'sonder_publish_update',
            'value'     => 'yes',
            'compare'   => '='
        ),
        array(
            'key'       => 'sonder_sent',
            'value'     => 'yes',
            'compare'   => '='
        )
    ),
  );


  $sonderNew = sonder_format_post($argsNew,true);
  $sonderExisting = sonder_format_post($argsExisting,false);
  $sonderPosts = array_merge($sonderNew,$sonderExisting);

  if (empty($sonderPosts)) {
    return;
  }

  // Post articles to sonders
  $response = wp_remote_post( 'http://content.sonder.news/parse', array(
      'method' => 'POST',
      'timeout' => 45,
      'redirection' => 5,
      'httpversion' => '1.0',
      'blocking' => true,
      'headers' => array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer '.$options['sonder_token']
      ),
      'body' => json_encode($sonderPosts),
      'cookies' => array()
    )
  );

  if ($response['response']['code'] == 401) {
    sonder_cron_status('The token is not correct');
  }elseif($response['response']['code'] == 200) { // success
    // Mark all new posts as sent
    foreach ($sonderPosts as $key => $aPost) {
      update_post_meta($aPost['id'], 'sonder_sent', 'yes' );
      update_post_meta($aPost['id'], 'sonder_publish_update', 'no' );
    }

    // Add status message  for options page
    sonder_cron_status('Everything went fine. <b>'.count($sonderPosts).'</b> posts were submitted');
  }else{
    sonder_cron_status($response['response']['message']);
  }

}

/**
 * Formats the article for the API
 * @version 0.1
 */
function sonder_format_post($args,$isNew)
{
  $the_query = new WP_Query( $args );

  // The Loop
  $sonderPosts = array();
  if ( $the_query->have_posts() ) {
    while ( $the_query->have_posts() ) {
      $the_query->the_post();
      $tags = get_the_tags();
      $tagsAr = array();
      if (!empty($tags)) {
        foreach($tags as $tag){
          $tagsAr[] = $tag->name;
        }
      }


      $sonderPosts[] = array(
        'id' => get_the_ID(),
        'url' => get_the_permalink(),
        'tags' => implode(',',$tagsAr),
        'isNew' => $isNew,
      );

  	}
  }
  /* Restore original Post Data */
  wp_reset_postdata();


  return $sonderPosts;
}

/**
 * Saves the last cron status
 * @version 0.1
 */
function sonder_cron_status($msg)
{
  $sonder_cron_log = get_option('sonder_cron_log');
  $sonder_cron_log[date('Y-m-d H:i:s')] = $msg;
  $sonder_cron_log = array_slice($sonder_cron_log, -12);
  update_option('sonder_cron_log',$sonder_cron_log);
}


/**
 * Renders the gerneral section
 * Deliberately empty
 * @version 0.1
 */
function sonder_settings_section_general()
{
}


function sonder_manualjob() {
    // Submit the Form
    if (isset($_GET['sonder_manualjob'])){
      sonder_submit_callback();
      wp_redirect( get_site_url().'/wp-admin/admin.php?page=sonder');
      exit;
    }
}
add_action ('wp_loaded', 'sonder_manualjob');
/**
 * The rest of the options page form
 * @version 0.1
 */
function sonder_options_page()
{
  // Redirect on manual submission
  $redirect_url =  get_site_url().'/wp-admin/admin.php?page=sonder';


?>

	<form action='options.php' method='post'>

		<h2>Sonder settings</h2>
    <?php
    $options = get_option('sonder_settings');
    $sonder_cron_log = get_option('sonder_cron_log');

    ?>
    <?php if(isset($options['saved_notice']) and !empty($options['saved_notice'])):?>
    <div class="updated settings-error notice is-dismissible" id="setting-error-settings_updated">
       <p><?php esc_html_e('Sonder settings was saved', 'sonder');?></p>
    </div>
    <?php endif;?>

		<?php
    $options['saved_notice'] = '';
    update_option('sonder_settings',$options);
    settings_fields('pluginPage');
    do_settings_sections('pluginPage');

    ?>
    <?php submit_button(); ?>
    <h2>Last 12 Submissions</h2>
    <p>
      Submissions to Sonder will automatically take place every hour.<br>
      Only posts that have the checkbox "Publish to Sonder" checked and status "Published" will be submitted. <br>
      (Scheduled posts will be submitted when published).
    </p>
    <?php if (isset($sonder_cron_log) and is_array($sonder_cron_log)): $i=1; foreach ($sonder_cron_log as $date => $msg) :?>
      <?php if(count($sonder_cron_log) === $i): ?>
        <hr>
        <b>Latest:</b><br>
        <i><?=$date?></i>: <?=$msg?> <br>
      <?php continue; endif; ?>

      <i><?=$date?></i>: <?=$msg?> <br>
    <?php $i++; endforeach; endif; ?>
    <br>
    <a href="<?=$redirect_url?>&sonder_manualjob=1">Trigger submission manually</a>
</form>
	<?php

}
