<?php

require_once('DBConfig.php');

/**
 * Класс Geoselector собирает данные yandex-геоселектора.
 *
 * Для того, чтобы начать сбор данных, нужно проинициализировать объект ([[initialize]]),
 * а потом запустить метод сбора данных ([[grabData]]). Класс использует конфигурацию бд
 * DBConfig (перед сбором данных все необходимые таблицы должны присутствовать в бд).
 */
class Geoselector
{
    /**
     * @var string адрес главной страницы селектора (нужно для получения куков и crc-кода).
     */
    private $frontPageUrl = 'https://realty.yandex.ru';

    /**
     * @var string базовые адрес, куда будут идти запросы к селектору.
     */
    private $postUrl = 'https://realty.yandex.ru/gate/geoselector/';

    /**
     * @var string контент главной страниц (для crc-кода) и response-хедеры (для куки).
     */
    private $frontPage;

    /**
     * @var string crc-код.
     */
    private $crc;

    /**
     * @var string cookie-хедер, который идет в post-запрос.
     */
    private $cookieHeader;

    /**
     * @var $db PDO.
     */
    private $db = null;

    /**
     * @var int сколько записей уже обработано.
     */
    private $counter = 0;

    /**
     * Инициализация.
     */
    public function initialize()
    {
        $this->getFrontPage();
        $this->extractCrc();
        $this->makeCookieHeader();
    }

    /**
     * Получение информации о регионе.
     * @param $geoId string|int идентификатор региона (rgid).
     * @return array ассоциативный-массив результата (current-region, refiniments, parents, subtree).
     */
    public function getQuery($geoId)
    {
        $request = $this->postRequest($this->postUrl . 'get', array('params[geoId]' => (string)$geoId));
        $arr = json_decode($request, true);
        return $arr['response'];
    }

    /**
     * @param $geoId string|int идентификатор региона (rgid).
     * @param $gid string|int первичный ключ региона (id).
     * @return array ассоциативный-массив результата (metro).
     */
    public function metroQuery($geoId, $gid)
    {
        $request = $this->postRequest($this->postUrl . 'metro', array('params[geoId]' => (string)$geoId, 'params[gid]' => (string)$gid));
        $arr = json_decode($request, true);
        return $arr['response'];
    }

    /**
     * @param $geoId string|int идентификатор региона (rgid).
     * @param $gid string|int первичный ключ региона (id).
     * @return array ассоциативный-массив результата (sub-localities).
     */
    public function sublocQuery($geoId, $gid)
    {
        $request = $this->postRequest($this->postUrl . 'sub-localities', array('params[geoId]' => (string)$geoId, 'params[gid]' => (string)$gid));
        $arr = json_decode($request, true);
        return $arr['response'];
    }

    /**
     * Получить главную страницу (с заголовками).
     *
     * Для проведения POST-запросов системе нужен crc-код, который присутствует на странице, а также куки.
     */
    private function getFrontPage()
    {
        $curl = curl_init($this->frontPageUrl);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HEADER, 1);

        $this->frontPage = curl_exec($curl);
    }

    /**
     * Получить crc-код из страницы.
     */
    private function extractCrc()
    {
        $arr = array();
        preg_match("/<body([\\s\\S]*?)onclick=\"return([\\s\\S]*?)\">/", $this->frontPage, $arr);

        $decodedJson = json_decode(htmlspecialchars_decode($arr[2]), true);
        $this->crc = $decodedJson['i-global']['crc'];
    }

    /**
     * Построить 'Set-cookie: ' строку для POST-запросов.
     */
    private function makeCookieHeader()
    {
        $arr = array();
        preg_match_all("/Set-Cookie:\\s([\\s\\S]*?);/", $this->frontPage, $arr);
        $this->cookieHeader = 'Cookie: ' . implode('; ', $arr[1]);
    }

    /**
     * @param $url string
     * @param $params array
     * @return string ответ сервера (json-строка)
     */
    private function postRequest($url, $params)
    {
        $params['crc'] = $this->crc;

        $headers = [
            'Content-type: application/x-www-form-urlencoded',
            $this->cookieHeader,
        ];

        $headerString = '';
        foreach ($headers as $header) {
            $headerString .= $header . "\r\n";
        }

        $result = false;
        while ($result == false) {
            $result = file_get_contents($url, false, stream_context_create(array(
                'http' => array(
                    'method' => 'POST',
                    'header' => $headerString,
                    'content' => http_build_query($params)
                )
            )));

            if ($result == false) {
                echo "file_get_content error. Trying again . . .\n";
            }
        }

        return $result;
    }

    /**
     * Запустить процесс сбора данных.
     */
    public function grabData()
    {
        $this->db = new PDO("mysql:host=" . DBConfig::$host . ";dbname=" . DBConfig::$defaultDb . ";charset=utf8",
            DBConfig::$user, DBConfig::$password);

        $this->db->query("DELETE FROM sublocality; DELETE FROM station; DELETE FROM region;");
        $this->grabEntity(0);

        $this->db = null;
    }

    /**
     * Получить и сохранить данные региона.
     * @param $geoId string|int идентификатор региона (rgid).
     */
    private function grabEntity($geoId)
    {
        $r = $this->getQuery($geoId);

        $cur = $r['current-region'];

        $parentId = $r['parents'][0]['id'];
        if (isset($r['refinements']) && in_array('metro', $r['refinements'])) {
            $hasMetro = 1;
        } else {
            $hasMetro = 0;
        }

        if (isset($r['refinements']) && in_array('sub-localities', $r['refinements'])) {
            $hasSubloc = 1;
        } else {
            $hasSubloc = 0;
        }

        $st = $this->db->prepare('INSERT INTO region (id, rgid, name, parent_id, has_metro, has_subloc) VALUES (:id, :rgid, :name, :parent_id, :has_metro, :has_subloc)');
        $st->execute(array('id' => $cur['id'], 'rgid' => $cur['rgid'], 'name' => $cur['name'], 'parent_id' => $parentId,
            'has_metro' => $hasMetro, 'has_subloc' => $hasSubloc));

        if ($hasMetro) {
            $this->grabMetro($cur['rgid'], $cur['id']);
        }

        if ($hasSubloc) {
            $this->grabSubloc($cur['rgid'], $cur['id']);
        }

        if (isset($r['subtree'])) {
            foreach ($r['subtree'] as $sub) {
                $this->grabEntity($sub['rgid']);
            }
        }

        $this->counter++;
        echo "Counter: " . $this->counter . "\n";
    }

    /**
     * Получить и сохранить данные метро.
     * @param $geoId string|int идентификатор региона (rgid).
     * @param $id string|int первичный ключ региона (id).
     */
    private function grabMetro($geoId, $id)
    {
        $r = $this->metroQuery($geoId, $id);

        $stations = $r['metro']['stations'];
        foreach ($stations as $station) {
            $st = $this->db->prepare('INSERT INTO station (id, name, region_id) VALUES (:id, :name, :region_id)');
            $st->execute(array('id' => $station['id'], 'name' => $station['name'], 'region_id' => $id));
        }
    }

    /**
     * Получить и сохранить данные районов.
     * @param $geoId string|int идентификатор региона (rgid).
     * @param $id string|int первичный ключ региона (id).
     */
    private function grabSubloc($geoId, $id)
    {
        $r = $this->sublocQuery($geoId, $id);

        $sublocs = $r['sub-localities'];
        foreach ($sublocs as $subloc) {
            $st = $this->db->prepare('INSERT INTO sublocality (id, name, region_id) VALUES (:id, :name, :region_id)');
            $st->execute(array('id' => $subloc['id'], 'name' => $subloc['name'], 'region_id' => $id));
        }
    }
}
