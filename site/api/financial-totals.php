<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// DirectSponsor Data Directory Configuration
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('SITE_INCOME_DIR', DS_DATA_DIR . '/payments/data/site-income/');
define('ACCOUNTS_DIR', DS_DATA_DIR . '/data/accounts/');
define('DATA_DIR', DS_DATA_DIR . '/data');

// Simplified functions (copied from accounts.php but without API logic)
function loadJsonData($filepath) {
    if (!file_exists($filepath)) {
        return [];
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle || !flock($handle, LOCK_SH)) {
        return [];
    }
    
    $content = fread($handle, filesize($filepath) ?: 0);
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return json_decode($content, true) ?: [];
}

function getSiteIncomeData() {
    return loadJsonData(SITE_INCOME_DIR . 'site-income.json');
}

function getDistributions() {
    return loadJsonData(DATA_DIR . '/manual_distributions.json');
}

function calculateCurrentBalance() {
    $donations = getSiteIncomeData();
    $distributions = getDistributions();
    
    // Total donations received
    $totalInflows = 0;
    foreach ($donations as $donation) {
        $totalInflows += $donation['amount_sats'];
    }
    
    // Total distributions sent out (handle manual_distributions.json structure)
    $totalOutflows = 0;
    if (isset($distributions['distributions'])) {
        foreach ($distributions['distributions'] as $monthData) {
            if (isset($monthData['payments'])) {
                foreach ($monthData['payments'] as $payment) {
                    $totalOutflows += $payment['amount_sats'];
                }
            }
        }
    }
    
    // Current month totals
    $currentMonth = date('Y-m');
    $currentMonthInflows = 0;
    
    foreach ($donations as $donation) {
        $date = new DateTime($donation['confirmed_at'] ?? $donation['created_at']);
        if ($date->format('Y-m') === $currentMonth) {
            $currentMonthInflows += $donation['amount_sats'];
        }
    }
    
    return [
        'current_balance_sats' => $totalInflows - $totalOutflows,
        'total_inflows_sats' => $totalInflows,
        'total_outflows_sats' => $totalOutflows,
        'current_month_inflows_sats' => $currentMonthInflows,
        'next_distribution_date' => date('Y-m-t'), // Last day of current month
        'days_until_distribution' => (new DateTime(date('Y-m-t')))->diff(new DateTime())->days
    ];
}

// Calculate monthly charity totals
function calculateCharityMonthlySummary() {
    $currentMonth = date('Y-m');
    
    // Get current month site income
    $donations = getSiteIncomeData(); // From existing accounts.php
    $monthDonations = 0;
    $uniqueDonors = [];
    
    foreach ($donations as $donation) {
        $donationMonth = date('Y-m', strtotime($donation['confirmed_at'] ?? $donation['created_at']));
        if ($donationMonth === $currentMonth) {
            $monthDonations += $donation['amount_sats'];
            if (!empty($donation['donor_name'])) {
                $uniqueDonors[$donation['donor_name']] = true;
            }
        }
    }
    
    // Get current month distributions (handle manual_distributions.json structure)
    $distributions = getDistributions();
    $monthDistributions = 0;
    
    if (isset($distributions['distributions'])) {
        foreach ($distributions['distributions'] as $monthKey => $monthData) {
            if ($monthKey === $currentMonth && isset($monthData['payments'])) {
                foreach ($monthData['payments'] as $payment) {
                    $monthDistributions += $payment['amount_sats'];
                }
            }
        }
    }
    
    return [
        'current_month' => $currentMonth,
        'donations_this_month' => $monthDonations,
        'distributions_this_month' => $monthDistributions,
        'active_donors_this_month' => count($uniqueDonors)
    ];
}

// Generate charity financial totals report
function generateFinancialTotals() {
    // Get charity fund status (existing logic)
    $charityTotals = calculateCurrentBalance(); // From existing accounts.php
    
    // Generate monthly summary
    $monthlySummary = calculateCharityMonthlySummary();
    
    $report = [
        'timestamp' => date('c'),
        'charity_fund' => [
            'current_total_sats' => $charityTotals['current_balance_sats'],
            'total_received_sats' => $charityTotals['total_inflows_sats'],
            'total_distributed_sats' => $charityTotals['total_outflows_sats'],
            'last_updated' => date('c'),
            'status' => $charityTotals['current_balance_sats'] == 0 ? 'fully_distributed' : 'holding_funds'
        ],
        'monthly_summary' => $monthlySummary,
        'data_sources' => [
            'site_income_file' => 'payments/data/site-income/site-income.json',
            'distributions_file' => 'payments/data/accounts/distributions.json',
            'manual_payments_file' => 'data/manual_distributions.json'
        ]
    ];
    
    // Save report to file for caching
    $reportFile = DATA_DIR . '/financial_totals.json';
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
    
    return $report;
}

// Main endpoint
$action = $_GET['action'] ?? 'current';

switch ($action) {
    case 'current':
    default:
        try {
            $report = generateFinancialTotals();
            echo json_encode($report, JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to generate financial totals: ' . $e->getMessage()]);
        }
        break;
        
    case 'donations':
        try {
            $donations = getSiteIncomeData();
            $monthlyTotals = [];
            
            foreach ($donations as $donation) {
                $date = new DateTime($donation['confirmed_at'] ?? $donation['created_at']);
                $monthKey = $date->format('Y-m');
                
                if (!isset($monthlyTotals[$monthKey])) {
                    $monthlyTotals[$monthKey] = [
                        'month' => $monthKey,
                        'donations' => [],
                        'total_sats' => 0,
                        'total_donations' => 0
                    ];
                }
                
                $monthlyTotals[$monthKey]['donations'][] = [
                    'donor_name' => $donation['donor_name'],
                    'amount_sats' => $donation['amount_sats'],
                    'date' => $donation['confirmed_at'] ?? $donation['created_at'],
                    'message' => $donation['donor_message'] ?? ''
                ];
                
                $monthlyTotals[$monthKey]['total_sats'] += $donation['amount_sats'];
                $monthlyTotals[$monthKey]['total_donations']++;
            }
            
            // Sort by month descending (newest first)
            krsort($monthlyTotals);
            
            echo json_encode([
                'success' => true,
                'monthly_inflows' => array_values($monthlyTotals)
            ], JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get donations: ' . $e->getMessage()]);
        }
        break;
        
    case 'distributions':
        try {
            $distributions = getDistributions();
            echo json_encode([
                'success' => true,
                'recent_distributions' => $distributions
            ], JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get distributions: ' . $e->getMessage()]);
        }
        break;
}
?>