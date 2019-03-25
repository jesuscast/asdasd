<?php

/*
Handled URLs:

Get all product ranges:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=product_range
Returns: JSON list of product ranges' dictionaries

Get product range:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=product_range&id=<id>
Returns: JSON dictionary with data for product range identified by ID

Note: Product ranges are created with `create` method, but it is not invoked
through a URL, but from `WooKiteEndpointPublishProduct::run`.
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'WOOKITE_PRODUCT_RANGES_TABLE_NAME', $wpdb->prefix . 'wookite_product_ranges' );

class WooKiteEndpointProductRanges extends WooKiteEndpoint {

	static $endpoint = 'product_range';

	public function __construct( $plugin ) {
		dbDelta('CREATE TABLE ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' (
            id BIGINT(20) UNSIGNED NOT NULL auto_increment,
            image_url VARCHAR(255) DEFAULT "",
            image_url_preview VARCHAR(255) DEFAULT "",
            title VARCHAR(255) DEFAULT "",
            category_id bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY  (id)
        )');
		$this->range_product_separator = get_option( 'wookite_rp_sep', ' ' );
		parent::__construct( $plugin );
	}

	public function handle_url() {
		if ( ! empty( $this->post ) ) {
			if ( ! empty( $this->post['update'] ) ) {
				wookite_json_output( $this->update( $this->post['update'] ) );
			}
			if ( ! empty( $this->post['delete'] ) ) {
				wookite_json_output( $this->delete_ranges( $this->post['delete'] ) );
			}
			wookite_json_error( 'E00', 'These POST requests are really, REALLY hard to use, aren\'t they? :-P' );
		}
		if ( ! empty( $_GET['id'] ) ) {
			$id = (int) $_GET['id'];
			if ( $id > 0 ) {
				wookite_json_output( $this->get_products( $id ), array( 'error' => 'no data with the given ID' ) );
			}
		}
		wookite_json_output( $this->list_ranges() );
	}

	public function create( $image_url, $image_url_preview, $title = null ) {
		global $wpdb;
		$data = array(
			'image_url' => $image_url,
			'image_url_preview' => $image_url_preview,
			'title' => $title,
		);
		if ( ! empty( $title ) ) {
			$parent_cat_id = $this->plugin->get_option( 'range_parent_cat' );
			if ( is_int( $parent_cat_id ) ) {
				$cat_id = $this->plugin->create_category( $title, $parent_cat_id, true );
				if ( $cat_id ) { $data['category_id'] = $cat_id;
				}
			}
		}
		$wpdb->insert( WOOKITE_PRODUCT_RANGES_TABLE_NAME, $data );
		$data['id'] = $wpdb->insert_id;
		$data['published'] = true;
		if ( $data['id'] ) { return $data;
		}
		return null;
	}

	public function delete_category( $cat_id, $prod_ids = null ) {
		if ( ! empty( $prod_ids ) ) {
			foreach ( $prod_ids as $id ) {
				wp_remove_object_terms( $id, array( $cat_id ), 'product_cat' );
			}
		}
		if ( ! isset( $this->categories_controler ) ) {
			$this->categories_controler = new WC_REST_Product_Categories_Controller();
		}
		$wp_rest_request = new WP_REST_Request( 'DELETE' );
		$wp_rest_request->set_body_params( array( 'id' => $cat_id ) );
		$res = $this->categories_controler->create_item( $wp_rest_request );
	}

	public function update( $new_values ) {
		global $wpdb;
		$range_id = $new_values['id'];
		$old_values = $this->get_range( $range_id );
		$old_title = (empty( $old_values['title'] ) ? '' : $old_values['title']);
		$new_title = (empty( $new_values['title'] ) ? '' : $new_values['title']);
		if ( $old_title !== $new_title ) {
			$new_range_title = $new_title;
			if ( ! empty( $old_title ) ) {
				$old_title .= $this->range_product_separator;
			}
			if ( ! empty( $new_title ) ) {
				$new_title .= $this->range_product_separator;
			}
			$old_len = strlen( $old_title );
			$products = $this->plugin->kite->get_products_by_range( $range_id );
			foreach ( $products as $id => $prod ) {
				$post = get_post( $id );
				$title = $post->post_title;
				if ( $old_len and substr( $title, 0, $old_len ) !== $old_title ) {
					continue;
				}
				$post->post_title =
					$new_title . ($old_len ? substr( $title, $old_len ) : $title);
				if ( $title !== $post->post_title ) {
					wp_update_post( $post );
				}
			}
			$update_values = array( 'title' => $new_range_title );
			$cat_id = $old_values['category_id'];
			if ( empty( $new_title ) ) {
				if ( ! empty( $cat_id ) ) {
					$this->delete_category( $cat_id, array_keys( $products ) );
					$update_values['id'] = null;
				}
			} else {
				if ( empty( $cat_id ) ) {
					$parent_cat_id = $this->plugin->get_option( 'range_parent_cat' );
					if ( is_int( $parent_cat_id ) ) {
						$cat_id = $this->plugin->create_category( $new_title, $parent_cat_id, true );
						$update_values['category_id'] = $cat_id;
						foreach ( $products as $id => $prod ) {
							wp_set_object_terms( $id, array( $cat_id ), 'product_cat', true );
						}
					}
				} else { 					wp_update_term( $cat_id, 'product_cat', array( 'name' => $new_title ) );
				}
			}
			$wpdb->update(
				WOOKITE_PRODUCT_RANGES_TABLE_NAME,
				$update_values,
				array( 'id' => $new_values['id'] )
			);
		}
		return null;
	}

	public function get_range( $id ) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			'SELECT id, image_url, image_url_preview, title, category_id
            FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . '
            WHERE id=%d',
			$id
		), ARRAY_A);
	}

	public function get_ranges( $ids ) {
		global $wpdb;
		return $wpdb->get_results(sprintf(
			'SELECT id, image_url, image_url_preview, title, category_id
            FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . '
            WHERE id in (%s)',
			implode( ', ', $ids )
		), ARRAY_A);
	}

	public function get_range_by_cat( $id ) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			'SELECT id, image_url, image_url_preview, title, category_id
            FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . '
            WHERE category_id=%d',
			$id
		), ARRAY_A);
	}

	public function get_ranges_by_cat( $ids ) {
		global $wpdb;
		return $wpdb->get_results(sprintf(
			'SELECT id, image_url, image_url_preview, title, category_id
            FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . '
            WHERE category_id in (%s)',
			implode( ', ', $ids )
		), ARRAY_A);
	}

	public function get_products( $id ) {
		$res = array();
		$products = $this->plugin->kite->get_products_by_range( (int) $id );
		foreach ( $products as $id => $prod ) {
			$prod['id'] = $id;
			$post_status = get_post_status( $id );
			$prod['enabled'] = ($post_status === 'publish' || $post_status === 'private');
			$variants = array();
			foreach ( $this->plugin->kite->get_variants_by_product( $id ) as $vid => $var ) {
				$var['id'] = $vid;
				$variants[] = $var;
			}
			$prod['variants'] = $variants;
			$res[] = $prod;
		}
		return $res;
	}

	public function all_ranges() {
		global $wpdb;
		static $ppp = 17;
		$offset = 0;
		$prids = $this->plugin->kite->get_all_ranges_ids();
		if ( $prids ) {
			return $wpdb->get_results(sprintf(
				'SELECT * FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' WHERE id IN (%s)',
				implode( ',', $prids )
			), ARRAY_A);
		} else { return array();
		}
	}

	public function list_ranges() {
		$results = array();
		foreach ( $this->all_ranges() as $product_range ) {
			$product_range['published'] = true;
			$results[] = $product_range;
		}
		return $results;
	}

	public function delete_ranges( $range_ids ) {
		global $wpdb;
		// Prepare IDs (convert to `int` and eliminate any doubles)
		$ids = array();
		foreach ( (array) $range_ids as $id ) {
			$ids[ (int) $id ] = true;
		}
		$range_ids = array_keys( $ids );
		// Get all child posts (products and their variants)
		$post_ids = $this->plugin->kite->get_post_children_ids( $range_ids );
		// Get ranges' dynamic categories
		$cat_ids = array();
		foreach ( $this->get_ranges( $range_ids ) as $data ) {
			$cat_id = $data['category_id'];
			if ( ! empty( $cat_id ) ) {
				$cat_id = (int) $cat_id;
				if ( $cat_id > 0 ) { $cat_ids[ $cat_id ] = true;
				}
			}
			$cat_ids = array_keys( $cat_ids );
		}
		$str_cat_ids = implode( ', ', $cat_ids );
		$str_post_ids = implode( ', ', $post_ids );
		if ( ! empty( $cat_ids ) ) {
			// Fast delete dynamic range categories
			$res = $wpdb->query(sprintf(
				'DELETE FROM ' . $wpdb->term_relationships . ' WHERE object_id IN (%s)',
				$str_post_ids
			));
			foreach ( array( 'termmeta', 'term_taxonomy', 'terms' ) as $table ) {
				if ( $res === false ) {
					break;
				} else { $res = $wpdb->query(sprintf(
					'DELETE FROM ' . $wpdb->$table . ' WHERE term_id IN (%s)',
					$str_cat_ids
				));
				}
			}
			// Fallback in case the above failed
			if ( $res === false ) {
				foreach ( $cat_ids as $id ) {
					$this->delete_category( $id, $prod_ids );
				}
			}
		}
		// Delete Kite's metadata for products and their variants
		$this->plugin->kite->delete_posts_data( $post_ids );
		// Delete Kite's ranges
		$wpdb->query(sprintf(
			'DELETE FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' WHERE id IN (%s)',
			implode( ',', $range_ids )
		));
		// Fast delete WP posts and meta
		$res = $wpdb->query(sprintf(
			'DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id IN (%s)',
			$str_post_ids
		));
		if ( $res !== false ) {
			$res = $wpdb->query(sprintf(
				'DELETE FROM ' . $wpdb->posts . ' WHERE ID IN (%s)',
				$str_post_ids
			));
		}
		// Fallback in case the above failed (might take a while)
		if ( $res === false ) {
			$this->plugin->set_max_execution_time( 3 * count( $post_ids ) );
			foreach ( $post_ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
		return null;
	}

}


