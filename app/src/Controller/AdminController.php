<?php

namespace App\Controller;

require __DIR__ . '/../../../vendor/autoload.php';

use App\Library\CalculateAttributes;
use App\Library\Helper;
use App\Model\Disciplina;
use App\Model\Grade;
use App\Model\GradeDisciplina;
use App\Model\Nota;
use App\Model\Usuario;
use PHPMailer\PHPMailer\Exception;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;
use Dompdf\Dompdf;
use App\Library\MailSender;
class AdminController
{

    private $container;

    public function __construct(\Container $container)
    {
        $this->container = $container;
    }

    public function dataLoadAction(Request $request, Response $response, $args)
    {
        if ($request->isPost() && isset($request->getUploadedFiles()['data'])) {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->getUploadedFiles()['data'];
            $curso = "";
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                $this->container->view['error'] = 'Erro no upload do arquivo, tente novamente!';
            } else {
                $extension = mb_strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
                if (!in_array($extension, $this->container->settings['upload']['allowedDataLoadExtensions'])) {
                    $this->container->view['error'] = 'Formato ou Tamanho do certificado inválido!';
                } else {
                    try {
                        set_time_limit(60 * 60); //Should not Exit
                        $data = Helper::processCSV($uploadedFile->file);
                        $affectedData = ['disciplinasAdded' => 0, 'usuariosAdded' => 0, 'usuariosUpdated' => 0];
                        $disciplinas = Helper::convertToIdArray($this->container->disciplinaDAO->getAll());
                        foreach ($data['disciplinas'] as $disc) {

                            if (isset($disciplinas[$disc['codigo']])) {
                                continue;
                            }

                            $disciplina = new Disciplina();
                            $disciplina->setCodigo($disc['codigo']);
                            $disciplina->setCarga($disc['carga']);
                            $this->container->disciplinaDAO->persist($disciplina);
                            $disciplinas[$disciplina->getIdentifier()] = $disciplina; //Added to existing Disciplinas
                            $affectedData['disciplinasAdded']++;
                        }
                        $this->container->disciplinaDAO->flush(); //Commit the transaction
                        $usuarios = Helper::convertToIdArray($this->container->usuarioDAO->getAllFetched());

                        $this->container->usuarioDAO->flush();

                        foreach ($data['usuarios'] as $user) {
                            $curso = $user['curso'];
                            if (isset($usuarios[$user['matricula']])) {
                                $usuario = $usuarios[$user['matricula']];
                                foreach ($usuario->getNotas() as $userNota) {
                                    $usuario->removeNota($userNota);
                                    $this->container->notaDAO->remove($userNota);
                                }
                                $usuario->setNome($user['nome']);
                                $usuario->setGrade($user['grade']);

                                $affectedData['usuariosUpdated']++;
                            } else {
                                $usuario = new Usuario();
                                $curso = $user['curso'];

                                if($curso == '65AC') {
                                    $curso = '65C';
                                }
                                if($curso == '65AB') {
                                    $curso = '65B';
                                }

                                $usuario->setCurso($curso);
                                $usuario->setMatricula($user['matricula']);
                                $usuario->setNome($user['nome']);
                                $usuario->setGrade($user['grade']);

                                $this->container->usuarioDAO->persist($usuario);
                                $affectedData['usuariosAdded']++;
                            }
                            foreach ($user['disciplinas'] as $disc) {
                                $nota = new Nota();
                                $nota->setEstado($disc['status']);
                                $nota->setValor($disc['nota']);
                                $nota->setPeriodo($disc['periodo']);
                                $nota->setDisciplina($disciplinas[$disc['codigo']]);
                                $usuario->addNota($nota);
                                $this->container->notaDAO->persist($nota);
                            }
                        }
                        $this->container->usuarioDAO->flush(); //Commit the transaction
                        $this->container->view['affectedData'] = $affectedData;

                        $this->container->view['success'] = true;
                    } catch (\Exception $e) {
                        $this->container->view['error'] = $e->getMessage();
                    }
                }
            }

            $this->deletaUsuariosDuplicados();

            $this->calculaIra();
            $this->calculaIraPeriodoPassado();
            $this->abreviaTodosNomes(false, $curso);
            $this->abreviaTodosNomes(true, $curso);

            $this->container->usuarioDAO->setPeriodoCorrente();
            $periodo = $this->getPeriodoAtual();

            $this->container->usuarioDAO->setActiveUsers($this->container->usuarioDAO->getUsersPeriodo($periodo));
            $this->container->usuarioDAO->deleteAbsentUsers($curso);

            return $response->withRedirect($this->container->router->pathFor('assignMedals'));
        }
        $usuario = $this->container->usuarioDAO->getUsuarioLogado();
        $this->container->view['usuario'] = $usuario;
        return $this->container->view->render($response, 'adminDataLoad.tpl');
    }

    public function deletaUsuariosDuplicados()
    {
        $usuarioDuplicados = $this->container->usuarioDAO->getUsuariosComMesmoNome();
        $idsParaDeletar = [];

        if ($usuarioDuplicados === null || !is_array($usuarioDuplicados)) {
            return;
        }

        foreach($usuarioDuplicados as $usuario) {
            $final = substr($usuario['matricula'], -2);

            if ($final === "AC") {
                $idsParaDeletar[] = $usuario['id'];
            }
        }

        foreach($idsParaDeletar as $id) {
            $this->container->usuarioDAO->deleteById($id);
        }

        return;
    }

    public function calculaIra()
    {
        $usuarios = $this->container->usuarioDAO->getAllFetched();

        /** @var Usuario $usuario */
        foreach ($usuarios as $usuario) {
            $somatorioNotasVezesCargas = 0;
            $somatorioCargas = 0;

            /** @var Nota $nota */
            foreach ($usuario->getNotas() as $nota) {
                if ($nota->getEstado() == "Matriculado" || $nota->getEstado() == "Trancado" || $nota->getEstado() == "Dispensado") {
                    continue;
                }

                $departamento = substr($nota->getDisciplina()->getCodigo(), 0, 3);

                if ($departamento != 'DCC' && $departamento != 'EST' && $departamento != 'MAT' && $departamento != 'FIS') {
                    continue;
                }

                $somatorioNotasVezesCargas += $this->calculaNotaVezesCarga($nota);
                $somatorioCargas += $nota->getDisciplina()->getCarga();
            }

            if ($somatorioCargas != 0) {
                $ira = $somatorioNotasVezesCargas / $somatorioCargas;
            } else {
                $ira = 0;
            }

            $usuario->setIra($ira);

            try {
                $this->container->usuarioDAO->save($usuario);
            } catch (\Exception $e) {
                echo $e;
            }
        }
    }

    public function calculaIraPeriodoPassado()
    {
        $usuarios = $this->container->usuarioDAO->getAllFetched();

        /** @var Usuario $usuario */
        foreach ($usuarios as $usuario) {
            if($usuario->getSituacao() == 1) {
                continue;
            }

            $somatorioNotasVezesCargas = 0;
            $somatorioCargas = 0;

            /** @var Nota $nota */
            foreach ($usuario->getNotas() as $nota) {

                if ($nota->getPeriodo() != $this->getPeriodoPassado()) {
                    continue;
                }

                if ($nota->getEstado() == "Matriculado" || $nota->getEstado() == "Trancado" || $nota->getEstado() == "Dispensado") {
                    continue;
                }

                $departamento = substr($nota->getDisciplina()->getCodigo(), 0, 3);

                if ($departamento != 'DCC' && $departamento != 'EST' && $departamento != 'MAT' && $departamento != 'FIS') {
                    continue;
                }

                $somatorioNotasVezesCargas += $this->calculaNotaVezesCarga($nota);
                $somatorioCargas += $nota->getDisciplina()->getCarga();
            }

            if ($somatorioCargas != 0) {
                $ira = $somatorioNotasVezesCargas / $somatorioCargas;
            } else {
                $ira = 0;
            }

            if ($somatorioCargas >= 60 * 4) {
                $usuario->setIraPeriodoPassado($ira);
            } else {
                $usuario->setIraPeriodoPassado(0);
            }

            try {
                $this->container->usuarioDAO->save($usuario);
            } catch (\Exception $e) {
                echo $e;
            }
        }
    }

    public function calculaNotaVezesCarga($nota){
        /** @var Nota $nota */

        if($nota->getValor() === 'A')
            return 100 * $nota->getDisciplina()->getCarga();

        if($nota->getValor() === 'B')
            return 90 * $nota->getDisciplina()->getCarga();

        if($nota->getValor() === 'C')
            return 80 * $nota->getDisciplina()->getCarga();

        return $nota->getValor() * $nota->getDisciplina()->getCarga();
    }


    public function abreviaTodosNomes($isPeriodoPassado, $curso){
        if($isPeriodoPassado)
            $usuarios = $this->container->usuarioDAO->getAllFetched10PrimeirosPorIraPeriodoPassado($curso);
        else
            $usuarios = $this->container->usuarioDAO->getAllFetched10PrimeirosPorIra($curso);


        /** @var Usuario $usuario */
        foreach ($usuarios as $usuario) {
            $nomeAbreviado =  $this->abreviaNome($usuario->getNome(), 123);
            $usuario->setNomeAbreviado($nomeAbreviado);

            try {
                $this->container->usuarioDAO->flush();
            }
            catch (\Exception $e) {
                echo $e;
            }
        }
    }

    public function abreviaNome($nome, $tamanhoMax){
        $deveAbreviar = true;

        //Se o nome cabe na linha sem abreviar, não devemos abreviar;
        if(strlen($nome) <= 30)
            return $nome;

        $offset = 1;

        while($deveAbreviar){
            $indicePrimeiraLetra = $this->indicePrimeiraLetraSobrenome($nome, $offset);
            $indiceUltimaLetra =  $this->indiceUltimaLetraSobrenome($nome, $offset);
            $tamanhoNome = $indiceUltimaLetra - $indicePrimeiraLetra;

            $nome[$indicePrimeiraLetra + 1] = '.';
            $nome = substr_replace($nome, '', $indicePrimeiraLetra + 2, $tamanhoNome - 1);

            if(strlen($nome) <= 30)
                $deveAbreviar = false;
            else
                $offset++;
        }

        return $nome;
    }

    //offset para indicar qual sobrenome deve ser abreviado. Por exemplo, contando de traz pra frente,
    // um nome com 2 sobrenomes e offset = 1 abreviria o segundo, pois 3 - 1 = 2. (3 seria o numero de 'nomes' total)
    public function indicePrimeiraLetraSobrenome($nome, $offset){
        $numEspacosEmBrancoTotal = substr_count($nome, ' ');
        $numEspacosEmBrancoContados = 0;

        for($i=0; $i<strlen($nome); $i++) {
            if($nome[$i] === ' ')
                $numEspacosEmBrancoContados++;

            if($numEspacosEmBrancoContados == $numEspacosEmBrancoTotal - $offset )
                return $i + 1;
        }

        return -1;
    }

    public function indiceUltimaLetraSobrenome($nome, $offset){
        $numEspacosEmBrancoTotal = substr_count($nome, ' ');
        $numEspacosEmBrancoContados = 0;

        for($i=0; $i<strlen($nome); $i++) {
            if($nome[$i] === ' ')
                $numEspacosEmBrancoContados++;

            if($numEspacosEmBrancoContados == $numEspacosEmBrancoTotal - $offset + 1)
                return $i - 1;
        }

        return -1;
    }

    public function gradeLoadAction(Request $request, Response $response, $args)
    {
        if ($request->isPost() && isset($request->getUploadedFiles()['data'])) {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->getUploadedFiles()['data'];

            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                $this->container->view['error'] = 'Erro no upload do arquivo, tente novamente!';
            } else {
                $extension = mb_strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
                if (!in_array($extension, $this->container->settings['upload']['allowedDataLoadExtensions'])) {
                    $this->container->view['error'] = 'Formato ou Tamanho do certificado inválido!';
                } else {
                    try {
                        $nomeArquivo = $uploadedFile->getClientFilename();

                        preg_match("/.+(?=-)/" , $nomeArquivo, $cursoGrade);
                        preg_match("/(?<=\-).+(?=\.)/", $nomeArquivo, $codigoGrade);

                        $data = Helper::processGradeCSV($uploadedFile->file);
                        $affectedData = ['disciplinasAdded' => 0];
                        $grade = new Grade();
                        $grade->setCodigo($codigoGrade[0]);
                        $grade->setCurso($cursoGrade[0]);
                        $this->container->gradeDAO->persist($grade);
                        $this->container->gradeDAO->flush();

                        $disciplinas = Helper::convertToIdArray($this->container->disciplinaDAO->getAll());
                        $this->container->view['vetor'] = $data;
                        $this->container->view['disciplinas'] = $disciplinas;

                        foreach ($data['disciplinas'] as $disc) {

                            if($disc['codigo'] == "Disciplinas Eletivas") {
                                break;
                            }

                            if(!isset($disc['codigo']) || !isset($disc['nome'])) {
                                continue;
                            }

                            $disciplinasGrade = new GradeDisciplina();
                            if (isset($disciplinas[$disc['codigo']])) {
                                $bool = 1;
                                $this->container->view['boolean'] = $bool;
                                $disciplinasGrade->setGrade($grade);
                                $disciplinasGrade->setDisciplina($disciplinas[$disc['codigo']]);
                                $disciplinasGrade->setPeriodo($disc['periodo']);
                                $disciplinasGrade->setTipo(0);
                                $this->container->gradeDisciplinaDAO->persist($disciplinasGrade);
                            }else{
                                $disciplina = new Disciplina();
                                $disciplina->setCodigo($disc['codigo']);
                                $disciplina->setCarga($disc['carga']);
                                $disciplina->setNome($disc['nome']);
                                $this->container->disciplinaDAO->persist($disciplina);
                                $disciplinas[$disciplina->getCodigo()] = $disciplina; //Added to existing Disciplinas
                                $disciplinasGrade->setGrade($grade);
                                $disciplinasGrade->setDisciplina($disciplina);
                                $disciplinasGrade->setPeriodo($disc['codigo']);
                                $disciplinasGrade->setTipo(0);
                                $this->container->gradeDisciplinaDAO->persist($disciplinasGrade);
                            }
                            $affectedData['disciplinasAdded']++;
                        }
                        $this->container->view['affectedData'] = $affectedData;
                        $this->container->view['success'] = true;
                        $this->container->disciplinaDAO->flush(); //Commit the transaction
                        $this->container->gradeDisciplinaDAO->flush();
                    } catch (\Exception $e) {
                        $this->container->view['error'] = $e->getMessage();
                    }
                }
            }
        }

        $usuario = $this->container->usuarioDAO->getUsuarioLogado();

        if($usuario->isAdmin()) {
            $grades = $this->container->gradeDAO->getAll();
        } else {
            $grades = $this->container->gradeDAO->getAllByCurso($usuario->getCurso());
        }

        $this->container->view['grades'] = $grades;

        return $this->container->view->render($response, 'adminGradeLoad.tpl');
    }

    public function adicionaNomeAsDisciplinas($data)
    {
        foreach ($data['disciplinas'] as $disc) {
            if(isset($disc['nome']) && $disc['nome'] != false){

                $disciplina = $this->container->disciplinaDAO->getByCodigo($disc['codigo']);

                if(isset($disciplina)) {
                    $disciplina->setNome($disc['nome']);
                    $this->container->disciplinaDAO->persist($disciplina);
                    $this->container->disciplinaDAO->flush(); //Commit the transaction
                }
            }
        }
        die();
    }

    public function adminData(Request $request, Response $response, $args)
    {
        return $this->container->view->render($response, 'data.tpl');
    }

    public function adminDashboardAction(Request $request, Response $response, $args){
        $usuario = $this->container->usuarioDAO->getUsuarioLogado();
        $parametro = $request->getParam('curso');

        $curso = null;
        if($usuario->isCoordenador()) {
            $curso = $usuario->getCurso();
        } elseif(isset($parametro)) {
            $curso = $parametro;
        }

        $this->container->view['countNaoLogaram'] = $this->container->usuarioDAO->getCountNaoLogaram($curso)[0]["COUNT(*)"];
        $this->container->view['countLogaram'] = $this->container->usuarioDAO->getCountLogaram($curso)[0]["COUNT(*)"];

        if(isset($curso)) {
            $this->container->view['top10Ira'] = $this->container->usuarioDAO->getTop10IraTotalPorCurso($curso);
            $this->container->view['top10IraPeriodoPassado'] = $this->container->usuarioDAO->getTop10IraPeriodoPorCurso($curso);
        }
        else {
            $this->container->view['top10Ira'] = $this->container->usuarioDAO->getTop10IraTotal();
            $this->container->view['top10IraPeriodoPassado'] = $this->container->usuarioDAO->getTop10IraPeriodo();
        }

        $this->container->view['periodoAtual'] = $this->getPeriodoAtual();
        $this->container->view['periodoPassado'] = $this->getPeriodoPassado();

        return $this->container->view->render($response, 'adminDashboard.tpl');
    }

    public function exportPDFAction(Request $request, Response $response, $args){
        $aluno = $this->container->usuarioDAO->getById($args['id']);
        $certificados = $this->container->certificadoDAO->getValidatedByUsuario($aluno);

        $caminhoImagem = realpath(__DIR__ . '/../../../public/img/logo_ufjf.png');
        $horas = $this->horasTotais($certificados);

        $html =  '<head><meta charset="UTF-8"></head>';
        $html .= '<img width="140"  height="80" align="right" src='. $caminhoImagem. '><div align="left" style="font-size: 80%"><p><b>UNIVERSIDADE FEDERAL DE JUIZ DE FORA</b><br>DEPARTAMENTO DE CIÊNCIA DA COMPUTAÇÃO - DCC <br> INSTITUTO DE CIÊNCIAS EXATAS-ICE<br>CAMPUS UNIVERSITÁRIO – SÃO PEDRO – JUIZ DE FORA – MG<br></p></div>';
        $html .= '<div style="margin-top: 5%" align="center"><p>PARECER</p></div>';
        $html .= '<div align="justify"><p>Com base na Resolução 03/2014 do Colegiado do Curso de Ciência da Computação, a Coordenação do Curso Noturno de Ciência da Computação apresenta parecer FAVORÁVEL ao pedido do discente '.$aluno->getNome().', matrícula '.$aluno->getMatricula().', e solicita cômputo de '. $horas .'<b> horas em atividades curriculares eletivas </b>, referente às atividades a seguir:</p></div>';
        $html .= '<table align="center" style="font-family: arial, sans-serif; border-collapse: collapse; width: 100%; ">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th style="border: 1px solid #dddddd; text-align: left; padding: 8px">Periodo</th>';
        $html .= '<th style="border: 1px solid #dddddd; text-align: left; padding: 8px">Tipo</th>';
        $html .= '<th style="border: 1px solid #dddddd; text-align: left; padding: 8px">Horas</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($certificados as $certificado){

            $periodoInicio = $this->getPeriodoInicioLegivel($certificado);
            $periodoFim = $this->getPeriodoFimLegivel($certificado);

            $html .= '<tr><td style="border: 1px solid #dddddd; text-align: left; padding: 8px">'. $periodoInicio;
            if($periodoFim > $periodoInicio)
                $html .= ' a ' . $periodoFim ."</td>";
            $html .= '<td style="border: 1px solid #dddddd; text-align: left; padding: 8px">'.$certificado->getNomeTipo(). ": " . $certificado->getNomeImpresso() . "</td>";
            $html .= '<td style="border: 1px solid #dddddd; text-align: left; padding: 8px">'.$certificado->getNumHoras(). "</td>" . "</tr>";
        }

        $html .= '</tbody>';
        $html .= '</table>';


        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream("aproveitamento.pdf",
            array(
                "Attachment" => true //Para realizar o download somente alterar para true
            ));
    }

    public function horasTotais($certificados){
        $horas = 0;

        foreach ($certificados as $certificado){
            $horas += $certificado->getNumHoras();
        }

        return $horas;
    }

    public function getPeriodoInicioLegivel($certificado) : string {
        $anoInicio  = $certificado->getDataInicio()->format('Y');
        $mesInicio  = $certificado->getDataInicio()->format('m');

        $periodo = $anoInicio . '.' . $this->formataSemestre($mesInicio);

        if($certificado->getDataInicio1() != null) {

            $anoInicio = $certificado->getDataInicio1()->format('Y');
            $mesInicio = $certificado->getDataInicio1()->format('m');

            $periodo .= '<br>' . $anoInicio . '.' . $this->formataSemestre($mesInicio);

        }

        if($certificado->getDataInicio2() != null) {
            $anoInicio = $certificado->getDataInicio2()->format('Y');
            $mesInicio = $certificado->getDataInicio2()->format('m');


            $periodo .= '<br>' . $anoInicio . '.' . $this->formataSemestre($mesInicio);
        }

        return $periodo;
    }

    public function getPeriodoFimLegivel($certificado) : string {
        $anoInicio  = $certificado->getDataFim()->format('Y');
        $mesInicio  = $certificado->getDataFim()->format('m');

        $periodo = $anoInicio . '.' . $this->formataSemestre($mesInicio);

        if($certificado->getDataFim1() != null) {

            $anoInicio = $certificado->getDataFim1()->format('Y');
            $mesInicio = $certificado->getDataFim1()->format('m');

            $periodo .= '<br>' . $anoInicio . '.' . $this->formataSemestre($mesInicio);

        }

        if($certificado->getDataFim2() != null) {
            $anoInicio = $certificado->getDataFim2()->format('Y');
            $mesInicio = $certificado->getDataFim2()->format('m');


            $periodo .= '<br>' . $anoInicio . '.' . $this->formataSemestre($mesInicio);
        }

        return $periodo;
    }


    public function formataSemestre($mesInicio){
        return $mesInicio > 6 ? '3' : '1';
    }

    public function listAlunosLogaramAction(Request $request, Response $response, $args){
        $this->container->view['alunos'] = $this->container->usuarioDAO->getAlunosLogaram();

        return $this->container->view->render($response, 'usuariosLogaram.tpl');
    }

    public function adminUserAction(Request $request, Response $response, $args)
    {
        $usuario = $this->container->usuarioDAO->getByIdFetched($args['id']);

        if(!$usuario) {
            return $response->withRedirect($this->container->router->pathFor('adminListUsers'));
        }


        $medalhasUsuario = $this->container->usuarioDAO->getMedalsByIdFetched($usuario->getId());

        $this->container->view['medalhas'] = $medalhasUsuario;
        $this->container->view['isAdmin'] = true;
        $this->container->view['usuario'] = $usuario;
        $this->container->view['todasMedalhas'] =  $this->container->usuarioDAO->getTodasMedalhas();

        $this->container->view['top10Ira'] = $this->container->usuarioDAO->getTop10IraTotalPorCurso($usuario->getCurso());
        $this->container->view['top10IraPeriodoPassado'] = $this->container->usuarioDAO->getTop10IraPeriodoPorCurso($usuario->getCurso());
        $this->container->view['periodoAtual'] = $this->getPeriodoAtual();
        $this->container->view['periodoPassado'] = $this->getPeriodoPassado();
        $this->container->view['posicaoGeral'] = $this->container->usuarioDAO->getPosicaoAluno($usuario->getId());
        $this->container->view['xpTotal'] = $this->container->usuarioDAO->getQuantidadeDisciplinasByGrade($usuario->getGrade(), $usuario->getCurso()) * 100;

        $this->container->view['grupos'] = Helper::getGruposComPontuacao($this->container, $usuario);
        $this->container->view['gruposCursoInteiro'] = Helper::getGruposComPontuacao($this->container, $usuario, true);

        return $this->container->view->render($response, 'home.tpl');
    }

    public function getPeriodoPassado()
    {
        $periodoAtual = $this->getPeriodoAtual();
        $semestre = intval($periodoAtual[4]);
        $ano = substr($periodoAtual, 0, 4);

        if($semestre == 1) {
            $anoAnterior = date('Y', strtotime($ano . " -1 year"));
            $periodoAnterior = $anoAnterior . 3;
        }
        else {
            $periodoAnterior = $ano . 1;
        }

        return intval($periodoAnterior);
    }

    public function getPeriodoAtual()
    {
        $ultimaCarga = explode("-", $this->container->usuarioDAO->getPeriodoCorrente());
        $ano = $ultimaCarga[0];
        $mes = intval($ultimaCarga[1]);
        
        if($mes > 6) {
            $periodo = $ano . 3;    
        }
        else {
            $periodo = $ano . 1;
        }

        return $periodo;
    }


    public function listPeriodizadosAction(Request $request, Response $response, $args)
    {
        $usuario = $this->container->usuarioDAO->getUsuarioLogado();

        $curso = null;
        $parametro = $request->getParam('curso');

        if ($usuario->isCoordenador()) {
            $curso = $usuario->getCurso();
        }
        elseif(isset($parametro)) {
            $curso = $parametro;
        }

        $this->container->view['users'] = $this->container->usuarioDAO->getPeriodizados($curso);

        return $this->container->view->render($response, 'periodizados.tpl');
    }

    public function setConcluido(Request $request, Response $response, $args)
    {
        $id = $request->getParsedBodyParam('id');

        $this->container->usuarioDAO->setTodasMedalhasPeriodo($id);
        $this->container->usuarioDAO->setSituacaoFormado($id);

        return  $response->withRedirect($this->container->router->pathFor('adminListUsers'));
    }

    public function unsetConcluido(Request $request, Response $response, $args)
    {
        $SEASON_FINALE = 21;
        $id = $request->getParsedBodyParam('id');

        for($periodo = 1; $periodo <= 9; $periodo++) {
            if ($this->concluiuPeriodo($id, $periodo) && !$this->container->usuarioDAO->temMedalhaPeriodo($id, $periodo) ) {
                $this->container->usuarioDAO->setPeriodoOneUser($id, $periodo);
            }
            if (!$this->concluiuPeriodo($id, $periodo) && $this->container->usuarioDAO->temMedalhaPeriodo($id, $periodo) ) {
                $this->container->usuarioDAO->unsetPeriodoOneUser($id, $periodo);
            }
        }

        if($this->container->usuarioDAO->temMedalhaPeriodo($id, $SEASON_FINALE)){
            $this->container->usuarioDAO->unsetPeriodoOneUser($id, $SEASON_FINALE);
        }

        $this->container->usuarioDAO->unsetSituacaoFormado($id);

        return  $response->withRedirect($this->container->router->pathFor('adminListUsers'));
    }

    public function concluiuPeriodo($userId, $periodo)
    {
        $user = $this->container->usuarioDAO->getById($userId);
        $user = $this->container->usuarioDAO->getSingleUsersNotasByGrade($userId, $user->getGrade())[0];
        $disciplinas = $this->container->usuarioDAO->getDisciplinasByGradePeriodo($user->getGrade(), $periodo, $user->getCurso());
        $cont = 0;

        $user_notas = $user->getNotas();

        foreach ($disciplinas as $disciplina){
            foreach ($user_notas as $un){
                if ($disciplina->getCodigo() == $un->getDisciplina()->getCodigo()) {
                    $cont++;
                }
                else if ($un->getDisciplina()->getCodigo() == $disciplina->getCodigo()."E") {
                    $cont++;
                }
            }
        }

        if(sizeof($disciplinas) > 0){
            if ($cont == sizeof($disciplinas)){
                return true;
            }
        }

        return false;
    }


    public function impersonarUsuario(Request $request, Response $response, $args)
    {
        $usuarioOriginal = $this->container->usuarioDAO->getUsuarioLogado();
        $idUsuario = $args['id'];

        $_SESSION['id'] = $idUsuario;
        $_SESSION['idOriginal'] = $usuarioOriginal->getId();
        $_SESSION['estaImpersonando'] = true;

        return $response->withRedirect($this->container->router->pathFor('home'));
    }

    public function sairImpersonar(Request $request, Response $response, $args)
    {
        if(isset($_SESSION['estaImpersonando'])) {
            $_SESSION['id'] = $_SESSION['idOriginal'];

            unset($_SESSION['idOriginal']);
            unset($_SESSION['estaImpersonando']);
        }

        return $response->withRedirect($this->container->router->pathFor('adminListUsers'));

    }

    public function compareUsers(Request $request, Response $response, $args)
    {
        $users = [];

        $pesquisaCurso = $request->getQueryParam('curso');
        $pesquisaNome = $request->getQueryParam('nome');

        $usuarioLogado = $this->container->usuarioDAO->getUsuarioLogado();


        if($pesquisaCurso === 'todos' || $usuarioLogado->isProfessor()) {
            $pesquisaCurso = 'todos';
        } else {
            $pesquisaCurso = $usuarioLogado->getCurso();
        }

        if($pesquisaCurso && $pesquisaNome) {
            $users = $this->container->usuarioDAO->getByMatriculaNomeCursoSemAcentoARRAY($pesquisaNome, $pesquisaCurso);
        } elseif($pesquisaCurso) {
            $users = $this->container->usuarioDAO->getAllByCursoARRAY($pesquisaCurso);
        } elseif($pesquisaNome) {
            $users = $this->container->usuarioDAO->getByMatriculaNomeARRAY($pesquisaNome);
        } else {
            $users = $this->container->usuarioDAO->getAllARRAY();
        }

        $this->container->view['haPesquisaPorCursoEspecifico'] = $pesquisaCurso !== 'todos';

        $this->container->view['users'] = $users;
        $this->container->view['usuarioLogado'] = $usuarioLogado;
        $this->container->view['pesquisaNome'] = $pesquisaNome;


        return $this->container->view->render($response, 'listUsersForComparison.tpl');
    }   
    
    public function seeComparison(Request $request, Response $response, $args)
    {
        $alunos = $request->getParsedBodyParam('user');
        $grupoAlunos = [];
        $grupoAlunosTotal = [];
        $nomesAlunos = [];

        if (count($alunos) > 5) {
            $this->container->view['users'] = $this->container->usuarioDAO->getAllARRAY();
            $this->container->view['error'] = "Você deve selecionar até <b>5</b> usuários. Número de usuários selecionados: <b>" . count($alunos) . "</b>";
            return $this->container->view->render($response, 'listUsersForComparison.tpl');
        }


        foreach ($alunos as $index => $aluno) {
            $alunos[$index] = $this->container->usuarioDAO->getById($aluno);
            $grupoAlunos[$index] = Helper::getGruposComPontuacao($this->container, $alunos[$index]);
            $grupoAlunosTotal[$index] = Helper::getGruposComPontuacao($this->container, $alunos[$index], true);

            $nomesAlunos[] = $alunos[$index]->getNome();
        }

        $maiorIra = $alunos[0];
        $grupos = array_keys($grupoAlunos[0]);

        foreach ($alunos as $index => $aluno) {
            if ($aluno->getIra() > $maiorIra->getIra()) {
                $maiorIra = $aluno;
            }
        }

        $maiorAlunoPorGrupo = [];
        foreach ($grupos as $grupo) {
            $maiorAlunoPorGrupo[$grupo] = $this->getTopAlunoNoGrupo($grupoAlunos, $grupo);
        }

        $this->container->view['alunos'] = $alunos;
        $this->container->view['maiorIra'] = $maiorIra;
        $this->container->view['grupoAlunos'] = $grupoAlunos;
        $this->container->view['nomesAlunos'] = $nomesAlunos;
        $this->container->view['grupoAlunosTotal'] = $grupoAlunosTotal;
        $this->container->view['maiorAlunoPorGrupo'] = $maiorAlunoPorGrupo;

        return $this->container->view->render($response, 'seeComparison.tpl');
    }

    public function getTopAlunoNoGrupo($grupoAlunos, $grupo)
    {
        $topAluno = 0;

        foreach ($grupoAlunos as $idAluno => $grupos) {
            if ($grupoAlunos[$idAluno][$grupo] > $grupoAlunos[$topAluno][$grupo]) {
                $topAluno = $idAluno;
            }
        }

        return $topAluno;
    }


    public function manualCoordenador(Request $request, Response $response, $args)
    {
        return $this->container->view->render($response, 'manualCoordenador.tpl');
    }
}