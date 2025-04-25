<?php
session_start();
require_once 'vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Style\Font;

// Create new PHPWord object
$phpWord = new PhpWord();

// Set default font and margins
$phpWord->setDefaultFontName('Times New Roman');
$phpWord->setDefaultFontSize(12);

// Add title page section with wider margins
$section = $phpWord->addSection(array(
    'marginLeft' => 1440,    // 1 inch margins
    'marginRight' => 1440,
    'marginTop' => 1440,
    'marginBottom' => 1440
));

// Add company logo with refined positioning
$section->addImage('images/ener.png', array(
    'width' => 200,
    'height' => 100,
    'alignment' => 'center',
    'marginTop' => 300,
    'marginBottom' => 300
));

// Add formal title with proper spacing
$section->addText('ENERGY DEMAND ANALYSIS REPORT', 
    array(
        'size' => 24,
        'bold' => true,
        'name' => 'Times New Roman',
        'color' => '000000',
        'allCaps' => true
    ),
    array(
        'alignment' => 'center',
        'spaceAfter' => 400,
        'spaceBefore' => 300,
        'lineHeight' => 1.5
    )
);

// Add elegant decorative line
$section->addText('____________________________________________________', 
    array('size' => 14, 'color' => '333333'),
    array('alignment' => 'center', 'spaceBefore' => 100, 'spaceAfter' => 200)
);

$section->addTextBreak(3);

// Add formal document details block
$section->addText('DOCUMENT DETAILS', 
    array('size' => 14, 'bold' => true, 'allCaps' => true),
    array('alignment' => 'left', 'spaceAfter' => 200)
);

// Add company details with consistent formatting
$detailsStyle = array('size' => 12, 'name' => 'Times New Roman');
$paragraphStyle = array('spacing' => 150, 'spaceAfter' => 120, 'lineHeight' => 1.5);

$section->addText('Company Name:     ' . ($_SESSION['company_name'] ?? 'Not Specified'), 
    $detailsStyle, $paragraphStyle
);
$section->addText('Gross Area:          ' . ($_SESSION['gross_area'] ?? 'Not Specified') . ' sq ft', 
    $detailsStyle, $paragraphStyle
);
$section->addText('Report Date:        ' . date('F d, Y'), 
    $detailsStyle, $paragraphStyle
);

$section->addTextBreak(4);

// Add prepared by section with enhanced styling
$section->addText('PREPARED BY', 
    array('size' => 14, 'bold' => true, 'allCaps' => true),
    array('alignment' => 'left', 'spaceAfter' => 200)
);

$section->addText('EnergAIze Corporation', 
    array('size' => 12, 'name' => 'Times New Roman', 'bold' => true),
    array('spacing' => 150, 'spaceAfter' => 60)
);
$section->addText('Energy Analysis and Optimization Specialists', 
    array('size' => 11, 'italic' => true),
    array('spacing' => 150)
);

// Add space before page break
$section->addTextBreak(6);

// Enhance executive summary section
$section = $phpWord->addSection(array('marginLeft' => 1440, 'marginRight' => 1440));
$section->addText('EXECUTIVE SUMMARY', 
    array('size' => 16, 'bold' => true, 'allCaps' => true),
    array('spaceAfter' => 200)
);

// Add executive summary with proper paragraph formatting
$section->addText($_SESSION['executive_summary'] ?? 'No executive summary available.',
    array('size' => 12),
    array('lineHeight' => 1.5, 'spaceAfter' => 120)
);

// Add page break
$section->addPageBreak();

// Add charts section
$section = $phpWord->addSection();
$section->addText('Energy Demand Analysis', array('size' => 16, 'bold' => true));

// Add building chart
if (isset($_FILES['buildingChart'])) {
    $buildingChartPath = $_FILES['buildingChart']['tmp_name'];
    $section->addImage($buildingChartPath, array(
        'width' => 500,
        'height' => 300,
        'alignment' => 'center'
    ));
}

$section->addTextBreak(2);

// Add type chart
if (isset($_FILES['typeChart'])) {
    $typeChartPath = $_FILES['typeChart']['tmp_name'];
    $section->addImage($typeChartPath, array(
        'width' => 500,
        'height' => 300,
        'alignment' => 'center'
    ));
}

// Save file
$objWriter = IOFactory::createWriter($phpWord, 'Word2007');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment;filename="Energy_Demand_Report.docx"');
$objWriter->save('php://output');
exit;
?> 