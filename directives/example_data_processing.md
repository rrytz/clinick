# Example Data Processing Directive

## Goal
Process patient data from the database and export it to a Google Sheet for analysis.

## Inputs
- Database connection parameters (from .env)
- Date range for data extraction
- Optional: Patient ID filter

## Tools/Scripts to Use
- `execution/process_patient_data.py` - Main processing script
- `execution/export_to_google_sheets.py` - Export script

## Outputs
- Google Sheet URL with processed data (deliverable)
- Temporary CSV file in `.tmp/` (intermediate)

## Steps
1. Connect to database using credentials from .env
2. Query patient data within specified date range
3. Apply data transformations (cleaning, formatting)
4. Save intermediate results to `.tmp/patient_data_temp.csv`
5. Export to Google Sheets using OAuth credentials
6. Return Google Sheet URL to user

## Edge Cases
- **Empty result set**: Return message indicating no data found for date range
- **Database connection failure**: Check .env credentials, retry once, then alert user
- **Google API rate limit**: Implement exponential backoff, max 3 retries
- **Large dataset (>10,000 rows):** Chunk into batches of 1,000 rows for export

## Notes
- Always validate date range inputs before querying
- Patient data is sensitive - ensure Google Sheet sharing settings are appropriate
- Clean up `.tmp/` files after successful export
