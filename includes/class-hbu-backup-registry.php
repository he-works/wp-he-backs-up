<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Backup_Registry {

    const OPTION_KEY = 'hbu_backup_registry';

    public static function get_all() {
        $data = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $data ) ) {
            return array();
        }
        // 최신순 정렬
        usort( $data, function( $a, $b ) {
            return $b['created_at'] - $a['created_at'];
        } );
        return $data;
    }

    /**
     * @param array $entry {
     *   id, filename, created_at, size_bytes,
     *   locations (array: 'local', 'gdrive'),
     *   gdrive_file_id
     * }
     */
    public static function add( $entry ) {
        $all = self::get_all();

        // 중복 ID 제거
        $all = array_filter( $all, function( $e ) use ( $entry ) {
            return $e['id'] !== $entry['id'];
        } );

        $all[] = $entry;
        update_option( self::OPTION_KEY, array_values( $all ) );
    }

    public static function remove( $id ) {
        $all     = self::get_all();
        $updated = array_filter( $all, function( $e ) use ( $id ) {
            return $e['id'] !== $id;
        } );
        update_option( self::OPTION_KEY, array_values( $updated ) );
    }

    public static function find_by_id( $id ) {
        foreach ( self::get_all() as $entry ) {
            if ( $entry['id'] === $id ) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * 특정 백업 엔트리에서 저장 위치 하나를 제거합니다.
     * 예: Google Drive 보존 정책으로 Drive 파일만 삭제됐을 때 사용.
     */
    public static function remove_location( $id, $location ) {
        $all = self::get_all();
        foreach ( $all as &$entry ) {
            if ( $entry['id'] === $id ) {
                $entry['locations'] = array_values( array_filter( $entry['locations'], function( $l ) use ( $location ) {
                    return $l !== $location;
                } ) );
                if ( $location === 'gdrive' ) {
                    $entry['gdrive_file_id'] = '';
                }
                break;
            }
        }
        update_option( self::OPTION_KEY, array_values( $all ) );
    }

    public static function update_locations( $id, $locations, $gdrive_file_id = '' ) {
        $all = self::get_all();
        foreach ( $all as &$entry ) {
            if ( $entry['id'] === $id ) {
                $entry['locations']     = $locations;
                $entry['gdrive_file_id'] = $gdrive_file_id;
                break;
            }
        }
        update_option( self::OPTION_KEY, array_values( $all ) );
    }
}
