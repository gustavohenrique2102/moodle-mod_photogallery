<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Portuguese language strings for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['animatedimagenotsupported'] =
    'A fotografia "{$a}" é animada. Apenas imagens estáticas JPEG, PNG e WebP são permitidas.';
$string['backtogallery'] =
    'Voltar para a galeria';
$string['batchuploadinfo'] =
    'Para enviar várias fotografias de uma vez, selecione os arquivos no computador e arraste todos para a área acima.';
$string['coverheading'] =
    'Imagem em destaque';
$string['coverimage'] =
    'Imagem em destaque';
$string['coverimage_help'] =
    'Esta imagem será exibida em primeiro lugar e ocupará a posição principal do mosaico. Caso nenhuma imagem seja adicionada, a primeira fotografia da galeria será usada automaticamente.';
$string['editmetadata'] =
    'Gerenciar galeria';
$string['editmetadatatitle'] =
    'Gerenciar galeria: {$a}';
$string['eventimagemetadataupdated'] =
    'Metadados da imagem atualizados';
$string['featuredimagefixed'] =
    'A imagem em destaque permanece na primeira posição. Para substituí-la, edite as configurações da galeria.';
$string['featuredimageposition'] =
    'Posição 1 — imagem em destaque fixa';
$string['galleryareatoolarge'] =
    'As fotografias ultrapassam o limite combinado de armazenamento da galeria de {$a}.';
$string['gallerysettings'] = 'Configurações da galeria';
$string['imagealt'] = 'Fotografia {$a->number} da galeria {$a->gallery}';
$string['imagedimensionstoolarge'] =
    'A fotografia "{$a->filename}" ultrapassa o limite de {$a->maxdimension} pixels por lado ou {$a->maxmegapixels} megapixels.';
$string['imageposition'] =
    'Fotografia {$a->current} de {$a->total}';
$string['imageprocessingunavailable'] =
    'O servidor não consegue processar com segurança o formato da imagem "{$a}".';
$string['images'] = 'Fotografias';
$string['images_help'] =
    'Envie fotografias estáticas JPEG, PNG ou WebP. A galeria pode conter até 100 fotografias, incluindo a imagem em destaque, com limite de 10 MB por arquivo e 200 MB no total. Gerencie a ordem de exibição em “Gerenciar galeria”.';
$string['imagetoolarge'] =
    'A fotografia "{$a}" ultrapassa o tamanho permitido por arquivo.';
$string['imagetotalpixelstoolarge'] =
    'As fotografias selecionadas ultrapassam o limite combinado de {$a} megapixels decodificados.';
$string['importseparator'] = 'OU';
$string['importzip'] = 'Importar pasta compactada';
$string['importzip_help'] =
    'Compacte fotografias estáticas JPEG, PNG ou WebP em um arquivo ZIP. As imagens compatíveis serão validadas e adicionadas automaticamente; o ZIP não será armazenado.';
$string['invalidimage'] =
    'O arquivo "{$a}" não é uma fotografia estática JPEG, PNG ou WebP válida.';
$string['invalidtargetposition'] =
    'Informe uma posição entre {$a->minimum} e {$a->maximum}.';
$string['invalidzip'] =
    'O arquivo enviado não é um ZIP válido.';
$string['invalidzipimage'] =
    'O arquivo "{$a}" possui uma extensão de imagem, mas seu conteúdo não é uma fotografia válida.';
$string['lightboxtitle'] = 'Visualização da fotografia';
$string['managephotos'] = 'Gerenciar fotos';
$string['managephotosintro'] =
    'Adicione novas fotografias, remova imagens existentes ou importe uma pasta compactada. Para enviar várias imagens simultaneamente, selecione os arquivos no computador e arraste todos para o campo de fotografias.';
$string['managephotosnotice'] =
    'As fotografias removidas deste gerenciador serão excluídas da galeria depois que você salvar as alterações.';
$string['managephotostitle'] =
    'Gerenciar fotos: {$a}';
$string['mediaconflict'] =
    'A fotografia foi substituída enquanto estava sendo processada. Nenhuma alteração foi feita.';
$string['medialockfailed'] =
    'A galeria está sendo atualizada por outro processo. Aguarde e tente novamente.';
$string['metadataconflict'] =
    'A galeria foi alterada enquanto esta página estava aberta. Suas alterações não foram salvas. Revise os valores atuais e tente novamente.';
$string['metadataintro'] =
    'Adicione uma legenda, um texto alternativo para pessoas que utilizam leitores de tela e defina a ordem de exibição. A imagem em destaque permanece sempre em primeiro lugar.';
$string['metadatalockfailed'] =
    'A galeria está sendo alterada por outra solicitação. Aguarde um momento e tente novamente.';
$string['metadataupdated'] =
    'As legendas e os textos alternativos foram atualizados.';
$string['metadatavaluetoolong'] =
    'Este metadado não pode conter mais de {$a} caracteres.';
$string['modulename'] = 'Galeria de fotos';
$string['modulename_help'] =
    '<h4>Principais recursos</h4>
    <p>Permite enviar várias fotografias, destacar uma imagem principal e apresentá-las em mosaico, grade e visualização ampliada.</p>

    <h4>Formas de utilização</h4>
    <p>A galeria pode ser utilizada para registros de eventos, apresentações institucionais, destaques, avisos visuais e conteúdos educacionais.</p>';
$string['modulename_summary'] =
    'Exibe fotografias em um mosaico na página do curso e em uma galeria completa com visualização ampliada.';
$string['modulename_tip'] =
    'Adicione textos alternativos objetivos e legendas úteis para tornar a galeria mais acessível.';
$string['modulenameplural'] = 'Galerias de fotos';
$string['movephotodown'] =
    '↓ Descer';
$string['movephotoup'] =
    '↑ Subir';
$string['movetoposition'] =
    'Mover';
$string['nextimage'] = 'Próxima fotografia';
$string['noautocompletioninline'] =
    'A conclusão baseada na visualização não pode ser usada porque esta atividade exibe as fotografias diretamente na página do curso.';
$string['noimages'] = 'Nenhuma fotografia foi adicionada a esta galeria.';
$string['nophotosmetadata'] =
    'A galeria ainda não possui fotografias para editar.';
$string['photoalttext'] =
    'Texto alternativo';
$string['photoalttext_help'] =
    'Descreva de forma objetiva o conteúdo visual da fotografia para pessoas que utilizam leitores de tela. Não repita expressões como “imagem de” ou “foto de”, salvo quando forem necessárias ao contexto.';
$string['photocaption'] =
    'Legenda';
$string['photocaption_help'] =
    'Texto visível que identifica ou contextualiza a fotografia. A legenda poderá ser exibida abaixo da imagem ou no visualizador ampliado.';
$string['photogallery:addinstance'] = 'Adicionar uma nova galeria de fotos';
$string['photogallery:manage'] = 'Administrar as fotografias da galeria';
$string['photogallery:view'] = 'Visualizar a galeria de fotos';
$string['photogalleryname'] = 'Nome da galeria';
$string['photogalleryname_help'] =
    'Informe um nome que identifique o evento, curso ou conjunto de fotografias.';
$string['photoitem'] =
    'Fotografia {$a}';
$string['photoorder'] =
    'Ordem da fotografia';
$string['photoorderupdated'] =
    'A ordem das fotografias foi atualizada.';
$string['photosimported'] =
    '{$a} fotografias foram importadas do arquivo ZIP.';
$string['photosupdated'] =
    'As fotografias da galeria foram atualizadas.';
$string['pluginadministration'] = 'Administração da galeria de fotos';
$string['pluginname'] = 'Galeria de fotos';
$string['previewcount'] = 'Fotos exibidas no mosaico';
$string['previewcount_help'] =
    'Define quantas fotografias serão apresentadas diretamente na página do curso ou na página inicial. Todas as demais fotografias continuarão disponíveis na página completa da galeria.';
$string['previewphotos'] = '{$a} fotografias';
$string['previousimage'] = 'Fotografia anterior';
$string['privacy:metadata'] =
    'A atividade Galeria de fotos armazena apenas as configurações, as fotografias e os metadados da galeria, sem associá-los a um usuário específico.';
$string['remainingphotos'] = '+{$a}';
$string['remainingphotosaccessible'] =
    'Mais {$a} fotografias estão disponíveis neste visualizador.';
$string['savemetadata'] =
    'Salvar legendas e acessibilidade';
$string['savephotos'] =
    'Salvar alterações nas fotos';
$string['targetposition'] =
    'Nova posição';
$string['targetposition_help'] =
    'Informe a posição em que a fotografia deve aparecer e clique em “Mover”. As demais fotografias serão reorganizadas automaticamente.';
$string['taskgeneratepreviews'] = 'Gerar prévias da Galeria de fotos';
$string['toomanyimages'] =
    'A galeria pode conter no máximo {$a} fotografias.';
$string['totalphotos'] = '{$a} fotografias';
$string['viewmorephotos'] = 'Ver mais fotos';
$string['zipareatoolarge'] =
    'As fotografias importadas ultrapassariam o limite total de armazenamento da galeria de {$a}.';
$string['zipcompressionratio'] =
    'A entrada compactada "{$a}" possui uma taxa de compressão insegura.';
$string['zipimagetoolarge'] =
    'A fotografia "{$a}" ultrapassa o limite permitido por arquivo.';
$string['zipinvalidpath'] =
    'A entrada "{$a}" do arquivo ZIP possui um caminho inválido ou inseguro.';
$string['zipnoimages'] =
    'O arquivo ZIP não contém fotografias compatíveis.';
$string['ziptoomanyentries'] =
    'O arquivo ZIP contém mais de {$a} entradas.';
