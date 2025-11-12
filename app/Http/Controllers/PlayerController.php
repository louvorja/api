<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function __construct() {}

    public function index(Request $request)
    {
        $id = $request->v;

        return "
            <!DOCTYPE html>
            <html style='border:0'>
            <head>
            <!-- // this is needed to force our embedded browser to run in EDGE mode -->
            <meta http-equiv='X-UA-Compatible' content='IE=edge'>

                <style>
                    *{margin:0;padding:0;}
                    html,body{
                        height:100%;
                        margin:0;
                        padding:0;
                        overflow:hidden;
                        background:#000;
                    }
                    iframe{
                        position:absolute;
                        width:100%;
                        height:100%;
                        top:0;
                        left:0;
                        border:0;
                    }
                </style>
            </head>
            <body style='border:0'>
                <div id='player'></div>

                <script>
                var tag = document.createElement('script');

                tag.src = 'https://www.youtube.com/iframe_api';
                var firstScriptTag = document.getElementsByTagName('script')[0];
                firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

                var player;
                function onYouTubeIframeAPIReady() {
                    player = new YT.Player('player', {
                    //height: window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight,
                    height: '100%',
                    width: '100%',
                    videoId: '$id',
                    position: 'relative',
                    playerVars: { 'autoplay': 1, 'controls': 2 }, // this is essential for autoplay
                    events: {
                        'onReady': onPlayerReady,
                        'onStateChange': onPlayerStateChange
                    }
                    });
                }

                // 4. The API will call this function when the video player is ready.
                function onPlayerReady(event) {
                    event.target.playVideo();
                }

                // 5. The API calls this function when the player's state changes.
                //    The function indicates that when playing a video (state=1),
                //    the player should play for six seconds and then stop.
                var done = false;
                function onPlayerStateChange(event) {
                    //if (event.data == YT.PlayerState.PLAYING && !done) {
                    //  setTimeout(stopVideo, 6000);
                    //  done = true;
                    //}
                }
                function stopVideo() {
                    player.stopVideo();
                }
                </script>
            </body>
            </html>
        ";
    }
}
