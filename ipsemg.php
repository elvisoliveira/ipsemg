<?php
require 'vendor/autoload.php';

$config = new Config_Lite('ipsemg.ini');
$client = new \GuzzleHttp\Client();

$headers = [
    'Content-Type' => 'application/json',
    'Cookie' => 'SERVERID=wso2-02'
];

$date = 'Y-m-d';

$url = 'https://apigateway.prodemge.gov.br';
$path = "{$url}/ipsemg/0.0.1";

$tgId = $config->get('telegram', 'user');
$notify = "[{$tgId}](tg://user?id={$tgId})";

while (true) {
    try {
        $body = new stdClass();
        $body->sessionId = null;
        $body->timestamp = null;
        $body->etapa = 'ETAPA_2';
        $body->codPaciente = $config->get('paciente', 'id');
        $body->idEspecialidade = (string) 43; // GINECOLOGIA E OBSTETRICIA
        $body->idSubespecialidade = (string) 38; // GINECOLOGIA
        $body->codProfissional = (string) $config->get('profissional', 'id');
        $body->codPreceptor = null;
        $body->idLocalAtendimento = null;
        $body->dataConsultaInicial = date($date);
        $body->dataConsultaFinal = date($date, strtotime('+120 day'));
        $body->idTipoLocalAtendimento = (string) 2; // BELO HORIZONTE - CENTRO DE ESPECIALIDADES MEDICAS
        $body->buscarMarcadasTambem = (string) false;
        $body->posicao = (string) 0;
        $body->qtdPorPagina = '';
        $body->usuarioPesquisou = $config->get('paciente', 'id');
        $body->origem = 'MEDICA_APP';

        $agenda = $client->post("{$path}/recuperarAgendasMarcadasDisponiveis", [
            'headers' => array_merge($headers, [
                'Authorization' => sprintf("Bearer %s", $config->get('headers', 'bearer'))
            ]),
            'body' => json_encode($body)
        ]);

        $body = $agenda->getBody()->getContents();
        $data = json_decode($body);

        logger($body);

        $marcacoesDisponiveis = $data->recuperarAgendasMarcadasDisponiveisResponse->return->marcacoesDisponiveis ?? false;
        if($marcacoesDisponiveis) {

            telegram("Agenda disponivel {$notify}");

            if (is_object($marcacoesDisponiveis)) {
                $marcacoesDisponiveis = [$marcacoesDisponiveis];
            }

            foreach($marcacoesDisponiveis as $marcacao) {

                $reserva = new stdClass();
                $reserva->digitoVerificador = (string) 3;
                $reserva->horaMinuto = $marcacao->horaMinuto;
                $reserva->idAgenda = (string) $marcacao->id;
                $reserva->idProfissional = (string) $config->get('profissional', 'id');
                $reserva->matriculaPaciente = (string) $config->get('paciente', 'id');
                $reserva->nomePaciente = $config->get('paciente', 'name');
                $reserva->origem = 'MEDICA_APP';
                $reserva->tipoConsulta = $marcacao->tipoConsulta;

                telegram("Realizando reserva {$notify}");

                $reservar = $client->post("{$path}/reservarMarcacao", [
                    'headers' => array_merge($headers, [
                        'Authorization' => sprintf("Bearer %s", $config->get('headers', 'bearer'))
                    ]),
                    'body' => json_encode($reserva)
                ]);

                $body = $reservar->getBody()->getContents();
                $data = json_decode($body);

                logger($body);

                $reservarMarcacao = $data->reservarMarcacaoResponse ?? false;
                if($reservarMarcacao && is_null($reservarMarcacao->return)) {

                    telegram("Reserva realizada {$notify}");

                    $efetua = new stdClass();
                    $efetua->agenda = new stdClass();
                    $efetua->agenda->sessionId = $marcacao->sessionId;
                    $efetua->agenda->timestamp = (string) (microtime(true) * 1000000000);
                    $efetua->agenda->id = $marcacao->id;
                    $efetua->agenda->descricaoEspecialidade = $marcacao->descricaoEspecialidade;
                    $efetua->agenda->permiteExclusao = $marcacao->permiteExclusao;
                    $efetua->agenda->especialidadeConsulta = $marcacao->especialidadeConsulta;
                    $efetua->agenda->subEspecialidade = $marcacao->subEspecialidade;
                    $efetua->agenda->permiteRegistrarAtendimento = $marcacao->permiteRegistrarAtendimento;
                    $efetua->matriculaBenficiario = $config->get('paciente', 'id');
                    $efetua->digitoVerificador = 3;
                    $efetua->nomePaciente = $config->get('paciente', 'name');
                    $efetua->matriculaMarcador = (string) $config->get('paciente', 'id');
                    $efetua->origem = 'MEDICA_APP';

                    $efetuar = $client->post("{$path}/efetuaMarcacao", [
                        'headers' => array_merge($headers, [
                            'Authorization' => sprintf("Bearer %s", $config->get('headers', 'bearer'))
                        ]),
                        'body' => json_encode($efetua)
                    ]);

                    $body = $efetuar->getBody()->getContents();
                    logger($body);
                }
                die();
            }
        }
        // else {
        //     telegram('Agenda não disponivel');
        // }
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $username = $config->get('headers', 'username');
        $password = $config->get('headers', 'password');
        $authorization = base64_encode(sprintf('%s:%s', $username, $password));

        telegram('Renovando token');

        $clientToken = $client->post("{$url}/token", [
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'Authorization' => sprintf('Basic %s', $authorization)
            ]),
            'body' => 'grant_type=client_credentials'
        ]);

        $body = $clientToken->getBody()->getContents();
        logger($body);

        $data = json_decode($body);
        if($data->access_token) {
            $config->set('headers', 'bearer', $data->access_token);
            $config->save();

            telegram('Token renovado');

            continue;
        }

        die();
    }

    sleep(10);
}

function logger($message) {
    $output = sprintf("%s: %s\n", date('F j, Y, g:i:s a'), $message);
    file_put_contents('ipsemg.log', $output, FILE_APPEND);
    print $output;
}

function telegram($message) {
    global $config;
    global $client;

    $bot = $config->get('telegram', 'bot');
    $token = $config->get('telegram', 'token');
    $client->request('GET', sprintf('https://api.telegram.org/bot%s:%s/sendMessage', $bot, $token), [
        'query' => [
            'chat_id' => $config->get('telegram', 'chat'),
            'text' => $message,
            'parse_mode' => 'MarkdownV2'
        ]
    ]);
}