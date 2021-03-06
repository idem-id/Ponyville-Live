<?php
namespace PVL;

use Entity\Convention;
use Entity\ConventionArchive;
use PVL\Service\YouTube;

class ConventionManager
{
    /**
     * Routine synchronization process.
     */
    public static function run()
    {
        $di = \Phalcon\Di::getDefault();
        $em = $di->get('em');

        $created_threshold = strtotime('-1 month');
        $sync_threshold = time()-3600;

        $records = $em->createQuery('SELECT ca FROM Entity\ConventionArchive ca WHERE (ca.created_at >= :created) AND (ca.synchronized_at <= :synced) AND (ca.playlist_id IS NULL) ORDER BY ca.synchronized_at ASC')
            ->setParameter('created', $created_threshold)
            ->setParameter('synced', $sync_threshold)
            ->execute();

        if (count($records) > 0)
        {
            foreach ($records as $row)
                self::process($row);
        }
    }

    /**
     * Process an individual convention archive row.
     * @param ConventionArchive $row
     */
    public static function process(ConventionArchive $row)
    {
        $di = \Phalcon\Di::getDefault();
        $em = $di->get('em');
        $config = $di->get('config');

        // Set up Google Client
        $gclient_api_key = $config->apis->google_apis_key;
        $gclient_app_name = $config->application->name;

        if (empty($gclient_api_key))
            return null;

        $gclient = new \Google_Client();
        $gclient->setApplicationName($gclient_app_name);
        $gclient->setDeveloperKey($gclient_api_key);

        $yt_client = new YouTube($gclient);

        $url = $row->web_url;

        if (empty($row->playlist_id))
        {
            switch($row->type)
            {
                case "yt_playlist":
                    $url_parts = \PVL\Utilities::parseUrl($url);
                    $playlist_id = $url_parts['query_arr']['list'];

                    if (!$playlist_id)
                        break;

                    // Clear existing related items.
                    $em->createQuery('DELETE FROM Entity\ConventionArchive ca WHERE ca.playlist_id = :id')
                        ->setParameter('id', $row->id)
                        ->execute();

                    $data = $yt_client->playlists->listPlaylists('id,snippet', array(
                        'id'        => $playlist_id,
                        'maxResults' => 1,
                    ));

                    if ($data)
                    {
                        $playlist = $data['items'][0]['snippet'];

                        $row->name = $playlist['title'];
                        $row->description = $playlist['description'];
                        $row->thumbnail_url = YouTube::getThumbnail($playlist['thumbnails']);
                    }

                    // Get playlist contents.
                    $data = $yt_client->getPlaylistItems($playlist_id);

                    foreach((array)$data as $item)
                    {
                        $row_name = self::filterName($row, $item['snippet']['title']);
                        $row_thumb = YouTube::getThumbnail($item['snippet']['thumbnails']);

                        // Apply name/thumbnail filtering to sub-videos.
                        if (!empty($row_name) && !empty($row_thumb))
                        {
                            $child_row = new ConventionArchive;
                            $child_row->convention = $row->convention;
                            $child_row->playlist_id = $row->id;
                            $child_row->type = 'yt_video';
                            $child_row->folder = $row->folder;

                            $child_row->name = $row_name;
                            $child_row->description = $item['snippet']['description'];
                            $child_row->web_url = 'http://www.youtube.com/watch?v=' . $item['id'];
                            $child_row->thumbnail_url = $row_thumb;

                            $em->persist($child_row);
                        }
                    }

                    $row->synchronized_at = time();
                    $em->persist($row);
                break;

                case "yt_video":
                default:
                    // Pull video ID from any URL format.
                    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match))
                        $video_id = $match[1];
                    else
                        break;

                    // Reformat video URL to match standard format.
                    $row->web_url = 'http://www.youtube.com/watch?v='.$video_id;

                    // Pull data from API.
                    $data = $yt_client->videos->listVideos('snippet,contentDetails', array(
                        'id'        => $video_id,
                        'maxResults' => 1,
                    ));

                    if ($data)
                    {
                        $video = $data['items'][0]['snippet'];

                        $row->name = self::filterName($row, $video['title']);
                        $row->description = $video['description'];
                        $row->thumbnail_url = YouTube::getThumbnail($video['thumbnails']);

                        $row->synchronized_at = time();
                        $em->persist($row);
                    }
                break;
            }
        }

        $em->flush();
    }

    /**
     * Internal recursive function for getting YT playlist items.
     *
     * @param $yt_client
     * @param $playlist_id
     * @param null $page_token
     */
    protected static function _getPlaylistItems($yt_client, $playlist_id, $page_token = null)
    {
        $data = $yt_client->playlistItems->listPlaylistItems('id,snippet,status,contentDetails', array(
            'playlistId'    => $playlist_id,
            'maxResults'    => 50,
            'pageToken'     => $page_token,
        ));

        if ($data)
        {
            $items = (array)$data['items'];

            if ($data['nextPageToken'])
                $items = array_merge($items, self::_getPlaylistItems($yt_client, $playlist_id, $data['nextPageToken']));

            return $items;
        }
        else
        {
            return array();
        }
    }

    public static function filterName(ConventionArchive $row, $name)
    {
        $con = trim($row->convention->name);
        $name = trim($name);

        // Halt processing if video is private.
        if (strcmp($name, 'Private video') === 0)
            return false;

        // Strip con name off front of footage.
        if (substr(strtolower($name), 0, strlen($con)) == strtolower($con))
            $name = substr($name, strlen($con));

        // Strip con name off end of footage.
        if (substr(strtolower($name), 0-strlen($con)) == strtolower($con))
            $name = substr($name, 0, strlen($name)-strlen($con));

        $name = trim($name, " -@:\t\n\r\0");
        return $name;
    }
}