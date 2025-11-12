<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
 */

$router->group(['middleware' => 'general'], function () use ($router) {

    $router->get('/', function () {
        return [];
    });

    $router->get('/json_db/{file}', 'DatabaseJsonController@index');

    $router->get('/download', 'DownloadController@index');
    $router->get('/version_log', 'VersionLogController@index');

    $router->group(['middleware' => 'api'], function () use ($router) {

        $router->get('/params', 'ParamsController@index');
        $router->get('/ftp', 'FtpController@index');
        $router->get('/onlinevideos', 'OnlineVideosController@index');

        $router->group(['prefix' => 'auth'], function () use ($router) {
            $router->post('/login', 'AuthController@login');

            $router->group(['middleware' => 'auth'], function () use ($router) {
                $router->post('/refresh-token', 'AuthController@refreshToken');
                $router->post('/refresh_token', 'AuthController@refreshToken');
                $router->get('/me', 'AuthController@me');
                $router->post('/logout', 'AuthController@logout');
                $router->post('/change-password',  'AuthController@changePassword');
            });
        });


        $router->group(['prefix' => 'admin', 'middleware' => ['auth', 'confirmed_pwd']], function () use ($router) {
            $router->group(['middleware' => 'access:users'], function () use ($router) {
                $router->get('/users', 'UserController@index');
                $router->post('/users', 'UserController@store');
                $router->get('/users/{id}', 'UserController@show');
                $router->put('/users/{id}', 'UserController@update');
                $router->delete('/users/{id}', 'UserController@destroy');
            });

            $router->get('/categories', 'CategoryController@index');
            $router->get('/categories/{id}', 'CategoryController@show');
            $router->group(['middleware' => 'access:categories'], function () use ($router) {
                $router->post('/categories', 'CategoryController@store');
                $router->put('/categories/{id}', 'CategoryController@update');
                $router->delete('/categories/{id}', 'CategoryController@destroy');
            });
            $router->get('/categories_albums', 'CategoryAlbumController@index');
            $router->get('/categories_albums/{id}', 'CategoryAlbumController@show');
            $router->group(['middleware' => 'access:categories_albums'], function () use ($router) {
                $router->post('/categories_albums', 'CategoryAlbumController@store');
                $router->put('/categories_albums/{id}', 'CategoryAlbumController@update');
                $router->delete('/categories_albums/{id}', 'CategoryAlbumController@destroy');
            });
            $router->get('/albums', 'AlbumController@index');
            $router->get('/albums/{id}', 'AlbumController@show');
            $router->group(['middleware' => 'access:albums'], function () use ($router) {
                $router->post('/albums', 'AlbumController@store');
                $router->put('/albums/{id}', 'AlbumController@update');
                $router->delete('/albums/{id}', 'AlbumController@destroy');
            });
            $router->get('/musics', 'MusicController@index');
            $router->get('/musics/{id}', 'MusicController@show');
            $router->group(['middleware' => 'access:musics'], function () use ($router) {
                $router->post('/musics', 'MusicController@store');
                $router->put('/musics/{id}', 'MusicController@update');
                $router->delete('/musics/{id}', 'MusicController@destroy');
            });
            $router->get('/albums_musics', 'AlbumMusicController@index');
            $router->get('/albums_musics/{id}', 'AlbumMusicController@show');
            $router->group(['middleware' => 'access:albums_musics'], function () use ($router) {
                $router->post('/albums_musics', 'AlbumMusicController@store');
                $router->put('/albums_musics/{id}', 'AlbumMusicController@update');
                $router->delete('/albums_musics/{id}', 'AlbumMusicController@destroy');
            });
            $router->get('/lyrics', 'LyricController@index');
            $router->get('/lyrics/{id}', 'LyricController@show');
            $router->group(['middleware' => 'access:lyrics'], function () use ($router) {
                $router->post('/lyrics', 'LyricController@store');
                $router->put('/lyrics/{id}', 'LyricController@update');
                $router->delete('/lyrics/{id}', 'LyricController@destroy');
            });
            $router->get('/files', 'FileController@index');
            $router->get('/files/{id}', 'FileController@show');
            /*   $router->group(['middleware' => 'access:files'], function () use ($router) {
            $router->post('/files', 'AlbumController@store');
            $router->put('/files/{id}', 'AlbumController@update');
            $router->delete('/files/{id}', 'AlbumController@destroy');
        });*/
        });


        $router->group(['prefix' => 'tasks'], function () use ($router) {
            $router->get('/', 'TaskController@index');
            $router->get('/refresh_configs', 'TaskController@refresh_configs');
            $router->get('/export_database', 'TaskController@export_database');
            $router->get('/refresh_files_size', 'TaskController@refresh_files_size');
            $router->get('/refresh_files_duration', 'TaskController@refresh_files_duration');
            $router->get('/refresh_online_videos', 'TaskController@refresh_online_videos');
            $router->get('/import_slides', 'TaskController@import_slides');
            $router->get('/export_database_json', 'TaskController@export_database_json');
        });

        $router->group(['prefix' => '{lang}', 'middleware' =>  'lang'], function () use ($router) {

            $router->get('/', function () {
                return [];
            });

            $router->get('/languages', 'LanguageController@index');

            $router->get('/config', 'ConfigController@index');
            $router->get('/configs', 'ConfigController@index');

            $router->get('/musics', 'MusicController@index');
            $router->get('/musics/{id}', 'MusicController@show');
            $router->get('/music/{id}', 'MusicController@show');

            $router->get('/categories', 'CategoryController@index');

            $router->get('/categories_albums', 'CategoryAlbumController@index');

            $router->get('/albums', 'AlbumController@index');
            $router->get('/albums/{id}', 'AlbumController@show');
            $router->get('/album/{id}', 'AlbumController@show');

            $router->get('/albums_musics', 'AlbumMusicController@index');

            $router->get('/lyrics', 'LyricController@index');

            $router->get('/hymnal', 'HymnalController@index');

            $router->get('/files', 'FileController@index');

            $router->get('/ftp', 'FtpController@index');
        });
    });

    $router->group(['prefix' => '{lang}', 'middleware' =>  'lang'], function () use ($router) {
        $router->get('/download', 'DownloadController@index');
    });

    $router->get('/player', 'PlayerController@index');
});
