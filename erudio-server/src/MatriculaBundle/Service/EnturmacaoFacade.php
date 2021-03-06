<?php

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *    @author Municipio de Itajaí - Secretaria de Educação - DITEC         *
 *    @updated 30/06/2016                                                  *
 *    Pacote: Erudio                                                       *
 *                                                                         *
 *    Copyright (C) 2016 Prefeitura de Itajaí - Secretaria de Educação     *
 *                       DITEC - Diretoria de Tecnologias educacionais     *
 *                        ditec@itajai.sc.gov.br                           *
 *                                                                         *
 *    Este  programa  é  software livre, você pode redistribuí-lo e/ou     *
 *    modificá-lo sob os termos da Licença Pública Geral GNU, conforme     *
 *    publicada pela Free  Software  Foundation,  tanto  a versão 2 da     *
 *    Licença   como  (a  seu  critério)  qualquer  versão  mais  nova.    *
 *                                                                         *
 *    Este programa  é distribuído na expectativa de ser útil, mas SEM     *
 *    QUALQUER GARANTIA. Sem mesmo a garantia implícita de COMERCIALI-     *
 *    ZAÇÃO  ou  de ADEQUAÇÃO A QUALQUER PROPÓSITO EM PARTICULAR. Con-     *
 *    sulte  a  Licença  Pública  Geral  GNU para obter mais detalhes.     *
 *                                                                         *
 *    Você  deve  ter  recebido uma cópia da Licença Pública Geral GNU     *
 *    junto  com  este  programa. Se não, escreva para a Free Software     *
 *    Foundation,  Inc.,  59  Temple  Place,  Suite  330,  Boston,  MA     *
 *    02111-1307, USA.                                                     *
 *                                                                         *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

namespace MatriculaBundle\Service;

use Doctrine\ORM\QueryBuilder;
use CoreBundle\ORM\AbstractFacade;
use MatriculaBundle\Entity\DisciplinaCursada;
use CursoBundle\Entity\Turma;
use MatriculaBundle\Entity\Enturmacao;
use CursoBundle\Service\VagaFacade;
use CoreBundle\ORM\Exception\IllegalOperationException;

class EnturmacaoFacade extends AbstractFacade {
    
    private $disciplinaCursadaFacade;
    private $vagaFacade;
    
    function setDisciplinaCursadaFacade(DisciplinaCursadaFacade $disciplinaCursadaFacade) {
        $this->disciplinaCursadaFacade = $disciplinaCursadaFacade;
    }
    
    function setVagaFacade(VagaFacade $vagaFacade) {
        $this->vagaFacade = $vagaFacade;
    }
    
    function getEntityClass() {
        return 'MatriculaBundle:Enturmacao';
    }
    
    function queryAlias() {
        return 'e';
    }
    
    function parameterMap() {
        return [
            'matricula' => function(QueryBuilder $qb, $value) {
                $qb->andWhere('matricula.id = :matricula')->setParameter('matricula', $value);
            },
            'turma' => function(QueryBuilder $qb, $value) {
                $qb->join('e.turma', 'turma')
                   ->andWhere('turma.id = :turma')->setParameter('turma', $value);
            },
            'encerrado' => function(QueryBuilder $qb, $value) {
                $qb->andWhere('e.encerrado = :encerrado')->setParameter('encerrado', $value);
            }
        ];
    }
    
    function uniqueMap($enturmacao) {
        return [
            ['matricula' => $enturmacao->getMatricula(), 'turma' => $enturmacao->getTurma(), 'encerrado' => 0]
        ];
    }
    
    function countByTurma(Turma $turma, $genero = '') {
        $qb = $this->orm->getManager()->createQueryBuilder()->select('COUNT(e.id)')
            ->from($this->getEntityClass(), 'e')
            ->join('e.turma', 't')->join('e.matricula', 'm')
            ->where('e.ativo = true')->andWhere('e.encerrado = false')
            ->andWhere('t.id = :turma')->setParameter('turma', $turma->getId());
        if ($genero) {
            $qb = $qb->join('m.aluno', 'a')->andWhere('a.genero = :masc')->setParameter('masc', 'M');
        }
        return $qb->getQuery()->getSingleScalarResult();
    }
    
    function executarMovimentacaoTurma(Enturmacao $origem, Enturmacao $destino) {
        $origem->encerrar();
        $this->orm->getManager()->merge($origem);
        $this->orm->getManager()->flush();
        $this->transferirDisciplinas($origem, $destino);
        if ($origem->getVaga()) {
            $this->liberarVaga($origem);
        }
        $this->ocuparVaga($destino);
    }
    
    function encerrarPorTransferencia(Enturmacao $enturmacao) {
        $enturmacao->encerrar();
        $this->orm->getManager()->merge($enturmacao);
        $this->orm->getManager()->flush();
        $this->encerrarDisciplinas($enturmacao, DisciplinaCursada::STATUS_INCOMPLETO);
        if ($enturmacao->getVaga()) {
           $this->liberarVaga($enturmacao);
	}
    }
    
    protected function prepareQuery(QueryBuilder $qb, array $params) {
        $qb->join('e.matricula', 'matricula')->join('matricula.aluno', 'aluno')->orderBy('aluno.nome');
    }
    
    protected function beforeCreate($enturmacao) {
        if ($this->possuiVagaAberta($enturmacao) == false) {
            throw new IllegalOperationException('Não existem vagas disponíveis nesta turma');
        }
    }
    
    protected function afterCreate($enturmacao) {
        $this->vincularDisciplinas($enturmacao);
        $this->ocuparVaga($enturmacao);
    }
    
    protected function afterRemove($enturmacao) {
        $this->excluirDisciplinas($enturmacao);
        $this->liberarVaga($enturmacao);
    }
    
    private function vincularDisciplinas(Enturmacao $enturmacao) {
        $matricula = $enturmacao->getMatricula();
        $disciplinasOfertadas = $enturmacao->getTurma()->getDisciplinas();
        $disciplinasEmAndamento = $this->disciplinaCursadaFacade
                ->findByMatriculaAndEtapa($matricula, $enturmacao->getTurma()->getEtapa());
        foreach ($disciplinasOfertadas as $disciplinaOfertada) {   
            $emAndamento = false;                     
            foreach ($disciplinasEmAndamento as $disciplinaCursada) {
                if($disciplinaCursada->getDisciplina()->getId() === $disciplinaOfertada->getDisciplina()->getId()) {
                    $disciplinaCursada->setEnturmacao($enturmacao);
                    $disciplinaCursada->setDisciplinaOfertada($disciplinaOfertada);
                    $this->orm->getManager()->merge($disciplinaCursada);
                    $emAndamento = true;
                    break;
                }                
            }
            if (!$emAndamento) {
                $disciplinaCursada = new DisciplinaCursada($matricula, $disciplinaOfertada->getDisciplina());
                $disciplinaCursada->setEnturmacao($enturmacao);
                $disciplinaCursada->setDisciplinaOfertada($disciplinaOfertada);
                $this->disciplinaCursadaFacade->create($disciplinaCursada);
            }
        }
        $this->orm->getManager()->flush();
    }
    
    private function transferirDisciplinas(Enturmacao $origem, Enturmacao $destino) {
        $disciplinasCursadas = $origem->getDisciplinasCursadas();
        foreach($disciplinasCursadas as $disciplinaCursada) {
            $disciplinaCursada->setEnturmacao($destino);
            foreach($destino->getTurma()->getDisciplinas() as $disciplinaOfertada) {
                if($disciplinaOfertada->getDisciplina()->getId() === $disciplinaCursada->getDisciplina()->getId()) {
                    $disciplinaCursada->setDisciplinaOfertada($disciplinaOfertada);
                    break;
                }
            }
        }
    }
    
    private function encerrarDisciplinas(Enturmacao $enturmacao, $status) {
        foreach ($enturmacao->getDisciplinasCursadas() as $disciplina) {
            $disciplina->setStatus($status);
            $this->orm->getManager()->merge($disciplina);
        }
        $this->orm->getManager()->flush();
    }
    
    private function excluirDisciplinas(Enturmacao $enturmacao) {
        $this->disciplinaCursadaFacade->removeBatch(
            $enturmacao->getDisciplinasCursadas()
                ->map(function($d) { return $d->getId(); })
                ->toArray()
        );
    }
    
    private function possuiVagaAberta(Enturmacao $enturmacao) {
        return $enturmacao->getTurma()->getVagasAbertas()->count() > 0;
    }
    
    private function ocuparVaga(Enturmacao $enturmacao) {
        $this->vagaFacade->ocupar($enturmacao->getTurma()->getVagasAbertas()->first(), $enturmacao);
    }
    
    private function liberarVaga(Enturmacao $enturmacao) {
        $this->vagaFacade->liberar($enturmacao->getVaga());
    }
    
}

