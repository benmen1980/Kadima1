<?php

/**
 * Create a new variable product (with new attributes if they are).
 * (Needed functions:
 *
 * @since 3.0.0
 * @param array $data | The data to insert in the product.
 */

function create_product_variable( $data ){

    $postname = sanitize_title( $data['title'] );
    $author = empty( $data['author'] ) ? '1' : $data['author'];

    $post_data = array(
        'post_author'   => $author,
        'post_name'     => $postname,
        'post_title'    => !empty($data['title']) ? $data['title'] : '',
        'post_content'  => !empty($data['content']) ? $data['content'] : '',
        'post_excerpt'  => !empty($data['excerpt']) ? $data['excerpt'] : '',
        'post_status'   => 'publish',
        'ping_status'   => 'closed',
        'post_type'     => 'product',
        'guid'          => home_url( '/product/'.$postname.'/' ),
    );

    $product_id = wc_get_product_id_by_sku($data['sku']);

    if (!empty($data['sku']) && $product_id) {
        $post_data['ID'] = $product_id;
        // Update the product (post data)
        $product_id = wp_update_post( $post_data );
    } else {
        // Creating the product (post data)
        $product_id = wp_insert_post( $post_data );
    }

    // Get an instance of the WC_Product_Variable object and save it
    $product = new WC_Product_Variable( $product_id );
    $product->save();

    // MAIN IMAGE
    if( ! empty( $data['image_id'] ) )
        $product->set_image_id( $data['image_id'] );

    // IMAGES GALLERY
    if( ! empty( $data['gallery_ids'] ) && count( $data['gallery_ids'] ) > 0 )
        $product->set_gallery_image_ids( $data['gallery_ids'] );

    // SKU
    if( ! empty( $data['sku'] ) )
        $product->set_sku( $data['sku'] );

    // STOCK (stock will be managed in variations)
    //$product->set_stock_quantity( $data['stock'] ); // Set a minimal stock quantity
    $product->set_manage_stock(false);
    $product->set_stock_status($data['stock']);

    // Tax class
    if( empty( $data['tax_class'] ) )
        $product->set_tax_class( $data['tax_class'] );

    // WEIGHT
    if( ! empty($data['weight']) )
        $product->set_weight(''); // weight (reseting)
    else
        $product->set_weight($data['weight']);

    $product->validate_props(); // Check validation

    ## ---------------------- VARIATION CATEGORIES ---------------------- ##


        if ($data['categories'] && is_array($data['categories']))
            wp_set_object_terms( $product_id, $data['categories'], 'product_cat' );

    ## ---------------------- VARIATION TAGS ---------------------- ##


    if ($data['tags'] && is_array($data['tags']))
        wp_set_object_terms( $product_id, $data['tags'], 'product_tag' );


    ## ---------------------- VARIATION ATTRIBUTES ---------------------- ##

    $product_attributes = array();

    foreach( $data['attributes'] as $key => $terms ){

        $taxonomy_id = wc_attribute_taxonomy_id_by_name($key);
        $taxonomy_name = wc_attribute_taxonomy_name( $key );

        if (!$taxonomy_id) {
            wc_create_attribute([
                'name' => $key,
            ]);
            register_taxonomy(
                $taxonomy_name,
                apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy_name, array( 'product' ) ),
                apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy_name, array(
                    'labels'       => array(
                        'name' => wc_sanitize_taxonomy_name($key),
                    ),
                    'hierarchical' => true,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ) )
            );
        }

        $product_attributes[$taxonomy_name] = array (
            'name'         => $taxonomy_name,
            'value'        => '',
            'position'     => '',
            'is_visible'   => 0,
            'is_variation' => 1,
            'is_taxonomy'  => 1
        );

        foreach( $terms as $value ){
            $term_name = ucfirst($value);
            $term_slug = sanitize_title($value);

            // Check if the Term name exist and if not we create it.
            if( ! term_exists( $value, $taxonomy_name ) )
                wp_insert_term( $term_name, $taxonomy_name, array('slug' => $term_slug ) ); // Create the term

            // Set attribute values
            wp_set_object_terms( $product_id, $term_name, $taxonomy_name, true );
        }
    }
    //$product_attributes = array_reverse($product_attributes, 1);
    update_post_meta( $product_id, '_product_attributes', $product_attributes );
    $product->save(); // Save the data

    return $product_id;
}

/**
 * Create a product variation for a defined variable product ID.
 *
 * @since 3.0.0
 * @param int   $product_id | Post ID of the product parent variable product.
 * @param array $variation_data | The data to insert in the product.
 */

function create_product_variation( $product_id, $variation_data ){
    // Get the Variable product object (parent)
    $product = wc_get_product($product_id);

    $variation_post = array(
        'post_title'  => $product->get_title(),
        'post_name'   => 'product-'.$product_id.'-variation',
        'post_status' => 'publish',
        'post_parent' => $product_id,
        'post_type'   => 'product_variation',
        'guid'        => $product->get_permalink()
    );

    $variation_id = wc_get_product_id_by_sku($variation_data['sku']);

    if (!empty($variation_data['sku']) && $variation_id) {
        $variation_post['ID'] = $variation_id;
        // Update the product variation
        $variation_id = wp_update_post( $variation_post );
    } else {
        // Creating the product variation
        $variation_id = wp_insert_post( $variation_post );
    }

    // Get an instance of the WC_Product_Variation object
    $variation = new WC_Product_Variation( $variation_id );

    if( ! empty( $variation_data['sku'] ) )
        $variation->set_sku( $variation_data['sku'] );

    // Iterating through the variations attributes
    foreach ($variation_data['attributes'] as $attribute => $term_name )
    {
        $taxonomy = 'pa_'.sanitize_title($attribute); // The attribute taxonomy

        // Check if the Term name exist and if not we create it.
        if( ! term_exists( $term_name, $taxonomy ) )
            wp_insert_term( $term_name, $taxonomy ); // Create the term

        $term_slug = get_term_by('name', $term_name, $taxonomy )->slug; // Get the term slug

        // Get the post Terms names from the parent variable product.
        $post_term_names =  wp_get_post_terms( $product_id, $taxonomy, array('fields' => 'names') );

        // Check if the post term exist and if not we set it in the parent variable product.
        if( ! in_array( $term_name, $post_term_names ) )
            wp_set_post_terms( $product_id, $term_name, $taxonomy, true );

        // Set/save the attribute data in the product variation
        update_post_meta( $variation_id, 'attribute_'.$taxonomy, $term_slug );
    }

    ## Set/save all other data

    // Prices
    if( empty( $variation_data['sale_price'] ) ){
        $variation->set_price( $variation_data['regular_price'] );
    } else {
        $variation->set_price( $variation_data['sale_price'] );
        $variation->set_sale_price( $variation_data['sale_price'] );
    }
    $variation->set_regular_price( $variation_data['regular_price'] );

    // Stock

    if( empty($variation_data['stock']) ){
        $variation->set_stock_status('outofstock');
    } else {
        $variation->set_stock_status($variation_data['stock']);
    }

    /*if( ! empty($variation_data['stock_qty']) ){
        $variation->set_stock_quantity( $variation_data['stock_qty'] );
        $variation->set_manage_stock(true);
        $variation->set_stock_status('');
    } else {
        $variation->set_manage_stock(false);
    }*/

    $variation->set_weight(''); // weight (reseting)

    $variation->save(); // Save the data
}