#!/usr/bin/env python3
"""
Example Data Processing Script
Deterministic execution layer for processing patient data.
"""

import os
import sys
from datetime import datetime
from pathlib import Path
import sqlite3
import csv

# Load environment variables
def load_env():
    env_file = Path(__file__).parent.parent / '.env'
    env_vars = {}
    if env_file.exists():
        with open(env_file, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip()
    return env_vars

def get_db_connection(env_vars):
    """Create database connection from environment variables."""
    db_path = env_vars.get('DB_NAME', 'clinick.db')
    return sqlite3.connect(db_path)

def process_patient_data(start_date, end_date, patient_id=None):
    """
    Process patient data from database.
    
    Args:
        start_date (str): Start date in YYYY-MM-DD format
        end_date (str): End date in YYYY-MM-DD format
        patient_id (str, optional): Filter by specific patient ID
    
    Returns:
        list: Processed patient records
    """
    env_vars = load_env()
    conn = get_db_connection(env_vars)
    cursor = conn.cursor()
    
    try:
        # Build query based on parameters
        query = """
            SELECT id, name, email, phone, created_at 
            FROM patients 
            WHERE created_at BETWEEN ? AND ?
        """
        params = [start_date, end_date]
        
        if patient_id:
            query += " AND id = ?"
            params.append(patient_id)
        
        cursor.execute(query, params)
        results = cursor.fetchall()
        
        # Process results
        processed_data = []
        for row in results:
            processed_data.append({
                'id': row[0],
                'name': row[1],
                'email': row[2],
                'phone': row[3],
                'created_at': row[4],
                'processed_date': datetime.now().isoformat()
            })
        
        return processed_data
        
    except sqlite3.Error as e:
        print(f"Database error: {e}", file=sys.stderr)
        raise
    finally:
        conn.close()

def save_to_csv(data, output_path):
    """
    Save processed data to CSV file.
    
    Args:
        data (list): List of dictionaries containing patient data
        output_path (str): Path to save CSV file
    """
    if not data:
        print("No data to save", file=sys.stderr)
        return False
    
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    
    with open(output_path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=data[0].keys())
        writer.writeheader()
        writer.writerows(data)
    
    return True

def main():
    """Main execution function."""
    import argparse
    
    parser = argparse.ArgumentParser(description='Process patient data')
    parser.add_argument('--start-date', required=True, help='Start date (YYYY-MM-DD)')
    parser.add_argument('--end-date', required=True, help='End date (YYYY-MM-DD)')
    parser.add_argument('--patient-id', help='Optional patient ID filter')
    parser.add_argument('--output', default='.tmp/patient_data_temp.csv', 
                       help='Output CSV file path')
    
    args = parser.parse_args()
    
    try:
        # Process data
        data = process_patient_data(args.start_date, args.end_date, args.patient_id)
        
        if not data:
            print("No records found for the specified criteria")
            return 0
        
        # Save to CSV
        output_path = Path(__file__).parent.parent / args.output
        if save_to_csv(data, str(output_path)):
            print(f"Successfully processed {len(data)} records")
            print(f"Output saved to: {output_path}")
            return 0
        else:
            print("Failed to save data", file=sys.stderr)
            return 1
            
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return 1

if __name__ == '__main__':
    sys.exit(main())
