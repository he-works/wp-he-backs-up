<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_DB_Dumper {

    /**
     * 데이터베이스를 SQL 파일로 덤프합니다.
     * mysqldump 명령어를 우선 시도하고, 실패 시 PHP로 직접 덤프합니다.
     *
     * @param string $dest_path 저장할 SQL 파일 경로
     * @return bool
     */
    public static function dump( $dest_path ) {
        HBU_Logger::info( 'DB 덤프 시작: ' . $dest_path );

        if ( self::dump_via_mysqldump( $dest_path ) ) {
            HBU_Logger::info( 'mysqldump로 DB 덤프 성공' );
            return true;
        }

        HBU_Logger::info( 'mysqldump 실패, PHP 방식으로 재시도' );
        return self::dump_via_php( $dest_path );
    }

    private static function dump_via_mysqldump( $dest_path ) {
        $mysqldump = self::find_mysqldump();
        if ( ! $mysqldump ) {
            return false;
        }

        $host     = DB_HOST;
        $port     = '3306';

        // host:port 형식 처리
        if ( strpos( DB_HOST, ':' ) !== false ) {
            list( $host, $port ) = explode( ':', DB_HOST, 2 );
        }

        // 소켓 경로 처리
        $socket_opt = '';
        if ( strpos( $host, '/' ) !== false ) {
            $socket_opt = ' --socket=' . escapeshellarg( $host );
            $host_opt   = '';
        } else {
            $host_opt = ' --host=' . escapeshellarg( $host ) . ' --port=' . escapeshellarg( $port );
        }

        // 비밀번호를 커맨드라인 인수로 노출하지 않기 위해 임시 설정 파일 사용
        $cnf_path = sys_get_temp_dir() . '/hbu_my_' . uniqid() . '.cnf';
        $cnf_lines = array(
            '[client]',
            'user='     . DB_USER,
            'password=' . DB_PASSWORD,
        );
        if ( $socket_opt ) {
            $cnf_lines[] = 'socket=' . $host;
        } else {
            $cnf_lines[] = 'host=' . $host;
            $cnf_lines[] = 'port=' . $port;
        }
        file_put_contents( $cnf_path, implode( "\n", $cnf_lines ) . "\n" );
        chmod( $cnf_path, 0600 );

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --no-tablespaces --single-transaction --quick --lock-tables=false%s %s > %s 2>&1',
            escapeshellcmd( $mysqldump ),
            escapeshellarg( $cnf_path ),
            $socket_opt ? '' : '',   // 소켓은 cnf 파일에 포함됨
            escapeshellarg( DB_NAME ),
            escapeshellarg( $dest_path )
        );

        exec( $cmd, $output, $return_code );
        @unlink( $cnf_path );

        if ( $return_code !== 0 ) {
            HBU_Logger::error( 'mysqldump 오류: ' . implode( ' ', $output ) );
            return false;
        }

        return file_exists( $dest_path ) && filesize( $dest_path ) > 0;
    }

    private static function find_mysqldump() {
        $candidates = array(
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
        );

        // which 명령어로도 탐색
        $out = array();
        @exec( 'which mysqldump 2>/dev/null', $out );
        if ( ! empty( $out[0] ) ) {
            $candidates[] = trim( $out[0] );
        }

        foreach ( $candidates as $bin ) {
            if ( is_executable( $bin ) ) {
                return $bin;
            }
        }

        return false;
    }

    private static function dump_via_php( $dest_path ) {
        global $wpdb;

        $handle = fopen( $dest_path, 'w' );
        if ( ! $handle ) {
            HBU_Logger::error( 'SQL 파일 생성 실패: ' . $dest_path );
            return false;
        }

        fwrite( $handle, "-- He Backs Up SQL Dump\n" );
        fwrite( $handle, "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" );
        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        $tables = $wpdb->get_col( 'SHOW TABLES' );

        foreach ( $tables as $table ) {
            HBU_Logger::info( '테이블 덤프: ' . $table );

            // CREATE TABLE
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( empty( $create[1] ) ) {
                HBU_Logger::error( 'CREATE TABLE 조회 실패: ' . $table );
                continue;
            }
            fwrite( $handle, "\nDROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $handle, $create[1] . ";\n\n" );

            // INSERT 데이터 (1000행씩 청크)
            $offset = 0;
            $chunk  = 1000;

            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunk, $offset ),
                    ARRAY_N
                );

                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $parts = array();
                    foreach ( $row as $val ) {
                        if ( is_null( $val ) ) {
                            $parts[] = 'NULL';
                        } else {
                            $parts[] = "'" . $wpdb->_real_escape( $val ) . "'";
                        }
                    }
                    fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $parts ) . ");\n" );
                }

                $offset += $chunk;
            } while ( count( $rows ) === $chunk );
        }

        fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $handle );

        HBU_Logger::info( 'PHP 방식 DB 덤프 성공' );
        return true;
    }
}
