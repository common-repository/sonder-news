<?php



/**
 * The sonder's box markup
 * @version 0.1
 */
function sonder_meta_box_markup($object)
{
    wp_nonce_field(basename(__FILE__), "sonder_nonce");

?>
        <div>
        
            <label for="sonder_publish">Publish to Sonder</label>
            <?php
    $sonder_settings           = get_option('sonder_settings');
    $checkbox_value            = get_post_meta($object->ID, "sonder_publish", 'yes');
    $sonder_publish_is_checked = '';
    if (empty($checkbox_value) and isset($sonder_settings['sonder_publish']) and $sonder_settings['sonder_publish'] == 1) {
        $sonder_publish_is_checked = 'checked';
    } elseif ($checkbox_value == 'yes') {
        $sonder_publish_is_checked = 'checked';
    }
?>
                    <input name="sonder_publish" type="hidden" value="no">
                    <input name="sonder_publish" type="checkbox" value="yes" <?= $sonder_publish_is_checked ?>>
                  </p>
          </div>
    <?php
}


/**
 * Adds the sonder box inside the post edit area
 * @version 0.1
 */
function sonder_add_meta_box()
{
    add_meta_box("sonder_box", "Sonder Box", "sonder_meta_box_markup", "post", "side", "high", null);
}

add_action("add_meta_boxes", "sonder_add_meta_box");


/**
 * Saves Sonder's fields and posts to Sonder's API if it meets the criteria
 * @version 0.1
 */
function sonder_save_meta_box($post_id, $post, $update)
{
    if (isset($_POST["sonder_publish"])) {
      $sonder_box_publish_value = $_POST["sonder_publish"];
      update_post_meta($post_id, "sonder_publish", $sonder_box_publish_value);
      update_post_meta($post_id, "sonder_publish_update", 'yes');
    }

}
add_action("save_post", "sonder_save_meta_box", 10, 3);
