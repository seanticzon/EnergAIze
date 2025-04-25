<?php
// Start session at the very beginning of the file, before any output
session_start();

include 'db_connect.php';
require_once 'vendor/autoload.php';

// Get the latest company profile and execute Python analysis
$query = "SELECT * FROM company_profiles ORDER BY company_id DESC LIMIT 1";
$result = mysqli_query($conn, $query);
$company = mysqli_fetch_assoc($result);

// Debug logging
error_log("Company data: " . print_r($company, true));

// Add null check before accessing array
if ($company && !empty($company['company_excel'])) {
    $excelPath = $company['company_excel'];
    error_log("Excel path: " . $excelPath);

    // Verify file exists
    if (!file_exists($excelPath)) {
        error_log("Excel file not found at path: " . $excelPath);
        $buildingDemand = "Error: Excel file not found.";
    } else {
        try {
            // Execute all Python scripts
            // First script (Building Demand)
            $modelPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'model.py';
            $command1 = sprintf('python "%s" "%s" 2>&1', 
                escapeshellarg($modelPath), 
                escapeshellarg($excelPath)
            );
            $buildingOutput = shell_exec($command1);
            error_log("Raw building demand output: " . $buildingOutput);

            // Second script (Type Demand)
            $model1Path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'model1.py';
            $command2 = sprintf('python "%s" "%s" 2>&1', 
                escapeshellarg($model1Path), 
                escapeshellarg($excelPath)
            );
            $typeOutput = shell_exec($command2);
            error_log("Raw type demand output: " . $typeOutput);

            // Third script (Combined Analysis)
            $model2Path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'model2.py';
            
            // Extract building and type analyses
            $buildingAnalysis = $buildingResponse['summary'] ?? '';
            $typeAnalysis = $typeResponse['summary'] ?? '';
            
            // Create analyses dictionary/array
            $analysesData = [
                'building_analysis' => $buildingAnalysis,
                'type_analysis' => $typeAnalysis
            ];
            
            // Create temporary JSON file with the analyses
            $tempFile = tempnam(sys_get_temp_dir(), 'analysis_');
            file_put_contents($tempFile, json_encode($analysesData));
            
            // Execute model2.py with the JSON file
            $command3 = sprintf('python "%s" "%s" 2>&1', 
                escapeshellarg($model2Path), 
                escapeshellarg($tempFile)
            );
            $combinedOutput = shell_exec($command3);
            error_log("Raw combined output: " . $combinedOutput);
            
            // Clean up temporary file
            unlink($tempFile);

            // Process all outputs
            // Process Building Demand
            if ($buildingOutput) {
                if (strpos($buildingOutput, "=== JSON Response ===") !== false) {
                    $parts = explode("=== JSON Response ===", $buildingOutput);
                    $jsonPart = trim(end($parts));
                    $buildingResponse = json_decode($jsonPart, true);
                } else {
                    $buildingResponse = json_decode($buildingOutput, true);
                }
                
                if ($buildingResponse && isset($buildingResponse['success'])) {
                    $buildingDemand = $buildingResponse['success'] ? 
                        $buildingResponse['summary'] : 
                        "Error generating building analysis: " . ($buildingResponse['error'] ?? 'Please try again.');
                }
            }

            // Process Type Demand
            if ($typeOutput) {
                if (strpos($typeOutput, "=== JSON Response ===") !== false) {
                    $parts = explode("=== JSON Response ===", $typeOutput);
                    $jsonPart = trim(end($parts));
                    $typeResponse = json_decode($jsonPart, true);
                } else {
                    $typeResponse = json_decode($typeOutput, true);
                }
                
                if ($typeResponse && isset($typeResponse['success'])) {
                    $typeDemand = $typeResponse['success'] ? 
                        $typeResponse['summary'] : 
                        "Error generating type analysis: " . ($typeResponse['error'] ?? 'Please try again.');
                }
            }

            // Process Combined Analysis
            if ($combinedOutput) {
                if (strpos($combinedOutput, "=== JSON Response ===") !== false) {
                    $parts = explode("=== JSON Response ===", $combinedOutput);
                    $jsonPart = trim(end($parts));
                    $combinedResponse = json_decode($jsonPart, true);
                } else {
                    $combinedResponse = json_decode($combinedOutput, true);
                }
                
                if ($combinedResponse && isset($combinedResponse['success'])) {
                    if ($combinedResponse['success']) {
                        $overallSummary = $combinedResponse['summary'];
                    } else {
                        $overallSummary = "Error generating overall analysis.";
                    }
                }
            }

            // Process Excel data
            $data = [];
            $years = [];
            $buildings = [];
            $types = [];

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($company['company_excel']);
            $worksheet = $spreadsheet->getActiveSheet();
            
            foreach ($worksheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }
                
                if ($rowData[0] !== 'building') {
                    $data[] = [
                        'building' => $rowData[0],
                        'type' => $rowData[1],
                        'year' => $rowData[2],
                        'demand' => $rowData[3]
                    ];
                    
                    if (!in_array($rowData[2], $years)) $years[] = $rowData[2];
                    if (!in_array($rowData[0], $buildings)) $buildings[] = $rowData[0];
                    if (!in_array($rowData[1], $types)) $types[] = $rowData[1];
                }
            }

            // Sort arrays
            sort($years);
            sort($buildings);
            sort($types);

            // Store everything in session
            $_SESSION['building_demand'] = $buildingDemand ?? "Error generating building analysis.";
            $_SESSION['type_demand'] = $typeDemand ?? "Error generating type analysis.";
            $_SESSION['executive_summary'] = $overallSummary ?? "Error generating overall analysis.";
            // Debug information
            $_SESSION['debug_building_output'] = $buildingOutput ?? 'No building output';
            $_SESSION['debug_type_output'] = $typeOutput ?? 'No type output';
            $_SESSION['debug_combined_output'] = $combinedOutput ?? 'No combined output';

            // Chart data
            $_SESSION['chart_data'] = [
                'years' => $years,
                'buildings' => $buildings,
                'types' => $types,
                'data' => $data,
            ];

        } catch (Exception $e) {
            error_log("Exception: " . $e->getMessage());
            $buildingDemand = $typeDemand = $combinedBuildingDemand = $combinedTypeDemand = "Error: " . $e->getMessage();
        }
    }
} else {
    $buildingDemand = "No company profile or Excel file found.";
    error_log("Missing company profile or Excel file path");
}

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Style\Font;
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'neon-blue': '#00F0FF',
                        'deep-purple': '#6E00FF',
                        'cyber-pink': '#FF2D55',
                        'electric-green': '#39FF14',
                        'space-black': '#0A0A0A',
                        'cyber-gray': '#1E1E1E',
                    },
                    fontFamily: {
                        'poppins': ['Poppins', 'sans-serif'],
                    },
                    animation: {
                        'float-slow': 'float 3s ease-in-out infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .neo-glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }
        .cyber-border {
            position: relative;
            border: 1px solid rgba(0, 240, 255, 0.2);
        }
        .cyber-border::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border: 2px solid transparent;
            border-radius: inherit;
            background: linear-gradient(45deg, #00F0FF, #6E00FF, #FF2D55) border-box;
            -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: destination-out;
            mask-composite: exclude;
            animation: borderRotate 4s linear infinite;
        }
        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            background-color: transparent;
            z-index: 0;
        }
        .relative-z {
            position: relative;
            z-index: 1;
        }
        .gegga { width: 0; }
        .snurra { filter: url(#gegga); }
        .stopp1 { stop-color: #f700a8; }
        .stopp2 { stop-color: #ff8000; }
        .halvan {
            animation: Snurra1 10s infinite linear;
            stroke-dasharray: 180 800;
            fill: none;
            stroke: url(#gradient);
            stroke-width: 23;
            stroke-linecap: round;
        }
        .strecken {
            animation: Snurra1 3s infinite linear;
            stroke-dasharray: 26 54;
            fill: none;
            stroke: url(#gradient);
            stroke-width: 23;
            stroke-linecap: round;
        }
        @keyframes Snurra1 {
            0% { stroke-dashoffset: 0; }
            100% { stroke-dashoffset: -403px; }
        }
    </style>
</head>
<body class="bg-space-black min-h-screen flex flex-col font-poppins text-gray-100">
    
    <!-- Add Particles Container -->
    <div id="particles-js" class="fixed inset-0 z-0 pointer-events-none"></div>

    <!-- Wrap existing content in relative-z -->
    <div class="relative-z">
        <!-- Header with glass effect -->
        <header class="neo-glass px-6 py-4 shadow-lg relative relative-z border-b border-neon-blue/20">
            <div class="absolute inset-0 bg-black opacity-10"></div>
            <nav class="container mx-auto flex flex-col sm:flex-row justify-between items-center gap-4 sm:gap-0 relative z-10">
                <a href="index.php" class="flex items-center space-x-3 hover:scale-105 transition-transform duration-300">
                    <i class="fas fa-bolt text-white text-3xl animate-float"></i>
                    <img src="images/ener.png" alt="energAIze" class="h-10 w-auto">
                </a>
                <div class="neo-glass px-6 py-2 rounded-full">
                    <div class="text-white">Export Report</div>
                </div>
            </nav>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto flex-grow px-4 py-12" x-data="{ shown: false }" x-init="setTimeout(() => shown = true, 100)">
            <div class="max-w-6xl mx-auto neo-glass rounded-2xl p-8 cyber-border transform transition-all duration-500"
                 x-show="shown"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 transform -translate-y-4"
                 x-transition:enter-end="opacity-100 transform translate-y-0">
                
                <!-- Report Header -->
                <div class="flex items-center space-x-6 mb-8 border-b border-gray-200/20 pb-8">
                    <div class="p-4 neo-glass cyber-border rounded-xl transform hover:scale-105 transition-all duration-300">
                        <i class="fas fa-file-word text-5xl bg-gradient-to-r from-neon-blue via-deep-purple to-cyber-pink bg-clip-text text-transparent animate-float-slow"></i>
                    </div>
                    <div class="transform hover:-translate-y-1 transition-transform duration-300">
                        <h2 class="text-4xl font-bold text-white relative group">
                            Report Preview
                            <span class="absolute -bottom-1 left-0 w-0 h-0.5 bg-gradient-to-r from-neon-blue via-deep-purple to-cyber-pink group-hover:w-full transition-all duration-300"></span>
                        </h2>
                        <p class="text-gray-400 mt-2 text-lg tracking-wide">
                            Review and export your energy analysis report
                            <span class="inline-block ml-2 text-neon-blue animate-pulse">•</span>
                        </p>
                    </div>
                </div>

                <!-- Charts Container -->
                <div id="reportContent" class="space-y-8">
                    <!-- Preview Container -->
                    <div class="neo-glass p-6 rounded-xl mb-8">
                        <h3 class="text-2xl font-bold text-white mb-4">Document Preview</h3>
                        <div class="bg-white rounded-lg p-6">
                            <!-- Company Logo -->
                            <div class="flex justify-center mb-8">
                                <?php if (!empty($company['company_logo']) && file_exists($company['company_logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($company['company_logo']); ?>" alt="Company Logo" class="h-20 object-contain">
                                <?php else: ?>
                                    <img src="images/ener.png" alt="energAIze" class="h-20">
                                <?php endif; ?>
                            </div>
                            
                            <!-- Title -->
                            <div class="text-center mb-8">
                                <h1 class="text-4xl font-bold text-gray-800">ENERGY DEMAND</h1>
                                <h1 class="text-4xl font-bold text-gray-800">ANALYSIS REPORT</h1>
                            </div>

                            <!-- Decorative Line -->
                            <div class="text-center mb-8">
                                <div class="text-gray-400 text-2xl">____________________</div>
                            </div>

                            <!-- Company Details -->
                            <div class="text-center mb-8">
                                <p class="text-gray-600 italic mb-2">Generated for:</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($company['company_name'] ?? 'COMPANY NAME NOT FOUND'); ?></p>
                            </div>

                            <!-- Building Details -->
                            <div class="text-center mb-8">
                                <p class="text-gray-600 italic mb-2">Building Gross Area:</p>
                                <p class="text-lg text-gray-800"><?php echo number_format($company['gross_area'], 2); ?> m²</p>
                            </div>

                            <!-- Date -->
                            <div class="text-center mb-8">
                                <p class="text-gray-600 italic mb-2">Report Generation Date:</p>
                                <p class="text-lg text-gray-800"><?php echo date('F d, Y'); ?></p>
                            </div>

                            <!-- Prepared By -->
                            <div class="text-center mb-8">
                                <p class="text-gray-600 italic mb-2">Prepared by:</p>
                                <p class="text-xl font-bold text-gray-800">EnergAIze Analytics Team</p>
                            </div>

                            <!-- Confidential Mark -->
                            <div class="text-center mb-8">
                                <p class="text-red-600 italic text-sm">CONFIDENTIAL DOCUMENT</p>
                            </div>

                            <!-- Page Break Indicator -->
                            <div class="border-b border-gray-200 my-8"></div>

                            <!-- Executive Summary -->
                            <div class="mb-8">
                                <h2 class="text-2xl font-bold text-gray-800 mb-4">Executive Summary</h2>
                                <p class="text-gray-700 text-justify">
                                    <?php echo nl2br(htmlspecialchars($_SESSION['executive_summary'])); ?>
                                </p>
                            </div>

                            <!-- Footer Preview -->
                            <div class="text-center text-gray-500 text-xs italic mt-12 pt-4 border-t">
                                energAIze Analytics | Confidential Document | Page 1 of {NUMPAGES}
                            </div>
                        </div>
                    </div>

                    <!-- Existing Charts -->
                    <div class="neo-glass p-6 rounded-xl">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Building Demand Analysis</h2>
                        <div class="text-gray-700 text-justify">
                            <?php echo nl2br(htmlspecialchars($_SESSION['building_demand'])); ?>
                        </div>
                        <div class="w-full h-[400px] bg-white rounded-lg p-4 mb-8">
                            <canvas id="buildingChart" style="width: 100%; height: 100%;"></canvas>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Building Typ Demand Analysis</h2>
                        <div class="text-gray-700 text-justify">
                            <?php echo nl2br(htmlspecialchars($_SESSION['type_demand'])); ?>
                        </div>
                        <div class="w-full h-[400px] bg-white rounded-lg p-4">
                            <canvas id="typeChart" style="width: 100%; height: 100%;"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Export Button -->
                <div class="mt-8 flex justify-center">
                    <button onclick="exportReport()" 
                            class="neo-glass bg-gradient-to-r from-neon-blue via-deep-purple to-cyber-pink text-white font-bold py-4 px-8 rounded-xl hover:shadow-[0_0_20px_rgba(0,240,255,0.5)] transition-all duration-300 group">
                        <span class="flex items-center justify-center gap-3">
                            <i class="fas fa-file-export text-xl group-hover:rotate-12 transition-transform"></i>
                            Export to DOCX
                            <i class="fas fa-arrow-right transition-transform group-hover:translate-x-2"></i>
                        </span>
                    </button>
                </div>
            </div>
        </main>

        <!-- Enhanced Footer -->
        <footer class="neo-glass border-t border-neon-blue/20 px-8 py-6 mt-12 relative-z">
            <div class="container mx-auto flex flex-col md:flex-row justify-between items-center text-white">
                <p class="flex items-center space-x-2">
                    <i class="fas fa-bolt animate-pulse"></i>
                    <span>&copy; 2024 energAIze - Powered by NLP</span>
                </p>
            </div>
        </footer>
    </div>


    <!-- Add Particles.js Configuration -->
    <script>
        particlesJS('particles-js', {
            particles: {
                number: { value: 100, density: { enable: true, value_area: 800 } },
                color: { value: ['#00F0FF', '#6E00FF', '#FF2D55', '#39FF14'] },
                shape: { type: 'circle' },
                opacity: {
                    value: 0.5,
                    random: true,
                    anim: { enable: true, speed: 1, opacity_min: 0.1, sync: false }
                },
                size: {
                    value: 3,
                    random: true,
                    anim: { enable: true, speed: 2, size_min: 0.1, sync: false }
                },
                line_linked: {
                    enable: true,
                    distance: 150,
                    color: '#A000C6',
                    opacity: 0.2,
                    width: 1
                },
                move: {
                    enable: true,
                    speed: 2,
                    direction: 'none',
                    random: true,
                    straight: false,
                    out_mode: 'out',
                    bounce: false,
                    attract: { enable: true, rotateX: 600, rotateY: 1200 }
                }
            },
            interactivity: {
                detect_on: 'canvas',
                events: {
                    onhover: { enable: true, mode: 'grab' },
                    onclick: { enable: true, mode: 'push' },
                    resize: true
                },
                modes: {
                    grab: { distance: 140, line_linked: { opacity: 0.5 } },
                    push: { particles_nb: 4 }
                }
            },
            retina_detect: true
        });
    </script>

    <!-- Existing export script -->
    <script>
        // Add this function before exportReport()
        async function prepareCanvasForExport(canvas) {
            // Get the chart instance
            const chart = Chart.getChart(canvas);
            if (!chart) {
                throw new Error('Chart instance not found');
            }

            // Force a resize to ensure proper rendering
            chart.resize();
            
            // Wait for any animations to complete
            await new Promise(resolve => setTimeout(resolve, 500));

            // Set white background
            const ctx = canvas.getContext('2d');
            ctx.save();
            ctx.globalCompositeOperation = 'destination-over';
            ctx.fillStyle = 'white';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Convert to PNG blob
            return new Promise((resolve) => {
                canvas.toBlob((blob) => {
                    ctx.restore();
                    resolve(blob);
                }, 'image/png', 1.0);
            });
        }

        async function exportReport() {
            try {
                const button = document.querySelector('button[onclick="exportReport()"]');
                const originalHTML = button.innerHTML;
                button.innerHTML = `<span class="flex items-center gap-2">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Generating Report...</span>
                </span>`;
                button.disabled = true;

                // Wait for content to be fully rendered
                await new Promise(resolve => setTimeout(resolve, 1000));

                // Get the preview content (title page)
                const previewContent = document.querySelector('.bg-white.rounded-lg.p-6');
                
                // Convert title page to image with proper styling preserved
                const titlePageCanvas = await html2canvas(previewContent, {
                    scale: 2, // Higher resolution
                    useCORS: true, // Enable loading of external images
                    logging: false,
                    backgroundColor: '#ffffff',
                    onclone: (clonedDoc) => {
                        // Ensure all fonts are loaded
                        const clonedElement = clonedDoc.querySelector('.bg-white.rounded-lg.p-6');
                        if (clonedElement) {
                            clonedElement.style.transform = 'none';
                            clonedElement.style.width = '210mm'; // A4 width
                            clonedElement.style.margin = '0';
                            clonedElement.style.padding = '20mm';
                        }
                    }
                });

                const titlePageBlob = await new Promise(resolve => {
                    titlePageCanvas.toBlob(resolve, 'image/png', 1.0);
                });

                // Get the chart canvases
                const buildingChartCanvas = document.getElementById('buildingChart');
                const typeChartCanvas = document.getElementById('typeChart');

                // Convert charts to images
                const [buildingChartBlob, typeChartBlob] = await Promise.all([
                    prepareCanvasForExport(buildingChartCanvas),
                    prepareCanvasForExport(typeChartCanvas)
                ]);

                // Create FormData and append all images
                const formData = new FormData();
                formData.append('titlePage', titlePageBlob, 'title_page.png');
                formData.append('buildingChart', buildingChartBlob, 'building_chart.png');
                formData.append('typeChart', typeChartBlob, 'type_chart.png');

                // Send request
                const response = await fetch('create_docx.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `Energy_Demand_Report_${new Date().toISOString().split('T')[0]}.docx`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);

            } catch (error) {
                console.error('Export failed:', error);
                alert(`Failed to export report: ${error.message}`);
            } finally {
                const button = document.querySelector('button[onclick="exportReport()"]');
                button.innerHTML = originalHTML;
                button.disabled = false;
            }
        }
    </script>

    <!-- Add this right after your chart canvas elements (around line 326) -->
    <script>
    // Get chart data from database
    <?php
    include 'db_connect.php';

    // Fetch data from company_profile and process the Excel file
    $query = "SELECT company_excel FROM company_profiles ORDER BY company_id DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);

    // Process Excel data
    $data = [];
    if ($row && $row['company_excel']) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($row['company_excel']);
        $worksheet = $spreadsheet->getActiveSheet();
        
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];
            
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue();
            }
            
            if ($rowData[0] !== 'building') { // Skip header row
                $data[] = [
                    'building' => $rowData[0],
                    'type' => $rowData[1],
                    'year' => $rowData[2],
                    'demand' => $rowData[3]
                ];
            }
        }
    }

    // Convert PHP array to JSON for JavaScript
    echo "const chartData = " . json_encode($data) . ";\n";
    ?>

    // Process data for Chart.js
    const years = [...new Set(chartData.map(item => item.year))];
    const buildings = [...new Set(chartData.map(item => item.building))];
    const types = [...new Set(chartData.map(item => item.type))];

    // Define cyberpunk color palette
    const chartColors = {
        backgrounds: [
            'rgba(0, 240, 255, 0.3)',    // neon-blue
            'rgba(110, 0, 255, 0.3)',    // deep-purple
            'rgba(255, 45, 85, 0.3)',    // cyber-pink
            'rgba(57, 255, 20, 0.3)',    // electric-green
            'rgba(255, 128, 0, 0.3)',    // cyber-orange
            'rgba(255, 0, 255, 0.3)',    // magenta
        ],
        borders: [
            'rgba(0, 240, 255, 1)',      // neon-blue
            'rgba(110, 0, 255, 1)',      // deep-purple
            'rgba(255, 45, 85, 1)',      // cyber-pink
            'rgba(57, 255, 20, 1)',      // electric-green
            'rgba(255, 128, 0, 1)',      // cyber-orange
            'rgba(255, 0, 255, 1)',      // magenta
        ]
    };

    // Building Chart - Stacked Area Chart
    const buildingChart = new Chart(
        document.getElementById('buildingChart'),
        {
            type: 'line',
            data: {
                labels: years,
                datasets: buildings.map((building, index) => ({
                    label: building,
                    data: years.map(year => {
                        const yearData = chartData.filter(item => 
                            item.building === building && 
                            item.year === year
                        );
                        return yearData.reduce((sum, item) => sum + Number(item.demand), 0);
                    }),
                    backgroundColor: chartColors.backgrounds[index % chartColors.backgrounds.length],
                    borderColor: chartColors.borders[index % chartColors.borders.length],
                    fill: true,
                    tension: 0.4
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' kWh';
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Energy Demand by Building',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                }
            }
        }
    );

    // Type Chart - Stacked Area Chart
    const typeChart = new Chart(
        document.getElementById('typeChart'),
        {
            type: 'line',
            data: {
                labels: years,
                datasets: types.map((type, index) => ({
                    label: type,
                    data: years.map(year => {
                        const yearData = chartData.filter(item => 
                            item.type === type && 
                            item.year === year
                        );
                        return yearData.reduce((sum, item) => sum + Number(item.demand), 0);
                    }),
                    backgroundColor: chartColors.backgrounds[index % chartColors.backgrounds.length],
                    borderColor: chartColors.borders[index % chartColors.borders.length],
                    fill: true,
                    tension: 0.4
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' kWh';
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Energy Demand by Type',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                }
            }
        }
    );
    </script>
</body>
</html>
