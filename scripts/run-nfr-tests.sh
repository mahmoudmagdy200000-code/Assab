#!/bin/bash

# NFR Test Runner Script
# This script runs all Non-Functional Requirements tests for the ASSAB system

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TEST_DIR="tests/NFR"
COVERAGE_THRESHOLD=80
VERBOSE=false
CATEGORY=""

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --category|-c)
            CATEGORY="$2"
            shift 2
            ;;
        --coverage)
            COVERAGE=true
            shift
            ;;
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  -c, --category CATEGORY    Run tests for specific category (Performance, Reliability, Security, etc.)"
            echo "  --coverage                 Generate code coverage report"
            echo "  -v, --verbose              Verbose output"
            echo "  -h, --help                 Show this help message"
            echo ""
            echo "Categories:"
            echo "  Performance, Reliability, Security, Usability, Compatibility, Maintainability, Scalability"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if we're in the project root
if [ ! -f "artisan" ]; then
    print_error "Please run this script from the Laravel project root directory"
    exit 1
fi

# Check if PHP is available
if ! command -v php &> /dev/null; then
    print_error "PHP is not installed or not in PATH"
    exit 1
fi

# Check if Composer dependencies are installed
if [ ! -d "vendor" ]; then
    print_warning "Composer dependencies not found. Installing..."
    composer install
fi

# Function to run tests
run_tests() {
    local test_path=$1
    local test_name=$2
    
    print_status "Running $test_name tests..."
    
    local cmd="php artisan test $test_path"
    
    if [ "$VERBOSE" = true ]; then
        cmd="$cmd --verbose"
    fi
    
    if [ "$COVERAGE" = true ]; then
        cmd="$cmd --coverage"
    fi
    
    if $cmd; then
        print_success "$test_name tests passed"
        return 0
    else
        print_error "$test_name tests failed"
        return 1
    fi
}

# Main execution
echo ""
echo "========================================="
echo "  ASSAB System NFR Test Runner"
echo "========================================="
echo ""

# Run tests based on category
if [ -n "$CATEGORY" ]; then
    case "$CATEGORY" in
        Performance)
            run_tests "$TEST_DIR/Performance" "Performance"
            ;;
        Reliability)
            run_tests "$TEST_DIR/Reliability" "Reliability"
            ;;
        Security)
            run_tests "$TEST_DIR/Security" "Security"
            ;;
        Usability)
            run_tests "$TEST_DIR/Usability" "Usability"
            ;;
        Compatibility)
            run_tests "$TEST_DIR/Compatibility" "Compatibility"
            ;;
        Maintainability)
            run_tests "$TEST_DIR/Maintainability" "Maintainability"
            ;;
        Scalability)
            run_tests "$TEST_DIR/Scalability" "Scalability"
            ;;
        *)
            print_error "Unknown category: $CATEGORY"
            echo "Available categories: Performance, Reliability, Security, Usability, Compatibility, Maintainability, Scalability"
            exit 1
            ;;
    esac
else
    # Run all NFR tests
    print_status "Running all NFR tests..."
    echo ""
    
    categories=(
        "Performance:$TEST_DIR/Performance"
        "Reliability:$TEST_DIR/Reliability"
        "Security:$TEST_DIR/Security"
        "Usability:$TEST_DIR/Usability"
        "Compatibility:$TEST_DIR/Compatibility"
        "Maintainability:$TEST_DIR/Maintainability"
        "Scalability:$TEST_DIR/Scalability"
    )
    
    failed_categories=()
    passed_categories=()
    
    for category_info in "${categories[@]}"; do
        IFS=':' read -r category_name category_path <<< "$category_info"
        if run_tests "$category_path" "$category_name"; then
            passed_categories+=("$category_name")
        else
            failed_categories+=("$category_name")
        fi
        echo ""
    done
    
    # Summary
    echo "========================================="
    echo "  Test Summary"
    echo "========================================="
    echo ""
    
    if [ ${#passed_categories[@]} -gt 0 ]; then
        print_success "Passed categories (${#passed_categories[@]}):"
        for category in "${passed_categories[@]}"; do
            echo "  ✓ $category"
        done
        echo ""
    fi
    
    if [ ${#failed_categories[@]} -gt 0 ]; then
        print_error "Failed categories (${#failed_categories[@]}):"
        for category in "${failed_categories[@]}"; do
            echo "  ✗ $category"
        done
        echo ""
        exit 1
    fi
    
    print_success "All NFR test categories passed!"
fi

echo ""
echo "========================================="
print_success "NFR testing completed successfully"
echo "========================================="
echo ""
