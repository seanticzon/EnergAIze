<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'vendor/autoload.php';
include 'db_connect.php';
session_start();

// Get the latest company profile from database
$query = "SELECT * FROM company_profiles ORDER BY company_id DESC LIMIT 1";
$result = mysqli_query($conn, $query);

if (!$result) {
    error_log("Database query failed: " . mysqli_error($conn));
    die("Failed to retrieve company data");
}

$company = mysqli_fetch_assoc($result);

if (!$company) {
    error_log("No company data found");
    die("No company data available");
}

try {
    // Create new Word document with default settings
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    
    // Set default font
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(11);

    // Add styles with validation
    $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 28, 'color' => '000000'], ['spacing' => 0]);
    $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 20, 'color' => '000000'], ['spacing' => 0]);
    
    // Section style with proper margins (in twips)
    $sectionStyle = [
        'orientation' => 'portrait',
        'marginTop' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(1),
        'marginBottom' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(1),
        'marginLeft' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(1),
        'marginRight' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(1)
    ];

    // Create Title Page section
    $section = $phpWord->addSection($sectionStyle);

    // Add company logo if available
    if (!empty($company['company_logo']) && file_exists($company['company_logo'])) {
        try {
            $section->addImage(
                $company['company_logo'],
                [
                    'width' => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(5),
                    'height' => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(2.5),
                    'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER
                ]
            );
        } catch (Exception $e) {
            error_log("Failed to add company logo: " . $e->getMessage());
        }
    }

    // Add title with proper spacing
    $section->addText('ENERGY DEMAND', 
        ['bold' => true, 'size' => 36, 'name' => 'Arial'], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spacing' => 0]
    );
    $section->addText('ANALYSIS REPORT', 
        ['bold' => true, 'size' => 36, 'name' => 'Arial'], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spacing' => 0]
    );

    // Add decorative line
    $section->addText('____________________', 
        ['size' => 24, 'color' => '808080'], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spacing' => 0]
    );

    // Add company details with proper text cleaning
    $section->addTextBreak(2);
    $section->addText('Generated for:', 
        ['italic' => true, 'size' => 12], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addText(
        htmlspecialchars($company['company_name'] ?? 'COMPANY NAME NOT FOUND'), 
        ['bold' => true, 'size' => 16], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );

    // Add building details with number formatting
    $section->addTextBreak(2);
    $section->addText('Building Gross Area:', 
        ['italic' => true, 'size' => 12], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addText(
        number_format(floatval($company['gross_area'] ?? 0), 2) . ' m²', 
        ['size' => 14], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );

    // Add date
    $section->addTextBreak(2);
    $section->addText('Report Generation Date:', 
        ['italic' => true, 'size' => 12], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addText(
        date('F d, Y'), 
        ['size' => 14], 
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );

    // Executive Summary section
    $section = $phpWord->addSection(['breakType' => 'nextPage'] + $sectionStyle);
    $section->addTitle('Executive Summary', 1);
    $section->addTextBreak(1);

    // Clean and add executive summary
    $executiveSummary = htmlspecialchars($_SESSION['executive_summary'] ?? 'No executive summary available.');
    $textrun = $section->addTextRun(['alignment' => 'justify']);
    $textrun->addText($executiveSummary, ['size' => 12]);

    // Process charts
    if (isset($_POST['buildingChart']) && isset($_POST['typeChart'])) {
        $section = $phpWord->addSection(['breakType' => 'nextPage'] + $sectionStyle);
        $section->addTitle('Energy Analysis Charts', 1);
        
        foreach (['buildingChart', 'typeChart'] as $chartName) {
            if (!empty($_POST[$chartName])) {
                try {
                    $imageData = base64_decode(explode(',', $_POST[$chartName])[1]);
                    $tempFile = tempnam(sys_get_temp_dir(), 'chart');
                    file_put_contents($tempFile, $imageData);
                    
                    $section->addTextBreak(2);
                    $section->addImage(
                        $tempFile, 
                        [
                            'width' => 450,
                            'height' => 300,
                            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER
                        ]
                    );
                    
                    unlink($tempFile);
                } catch (Exception $e) {
                    error_log("Failed to add chart $chartName: " . $e->getMessage());
                }
            }
        }
    }

    // Save document with proper settings
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $tempFile = tempnam(sys_get_temp_dir(), 'report');
    
    // Clear any previous output
    if (ob_get_level()) ob_end_clean();
    
    $objWriter->save($tempFile);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="Energy_Demand_Report.docx"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($tempFile));
    
    readfile($tempFile);
    unlink($tempFile);
    exit();

} catch (Exception $e) {
    error_log("Error generating document: " . $e->getMessage());
    if (isset($tempFile) && file_exists($tempFile)) {
        unlink($tempFile);
    }
    die("Failed to generate document: " . $e->getMessage());
}
?> 