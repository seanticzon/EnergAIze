import pandas as pd
from huggingface_hub import InferenceClient
import sys
import json

def process_energy_data_from_excel(excel_path: str, api_key: str) -> str:
    # Read Excel file
    df = pd.read_excel(excel_path)

    # Initialize Inference Client
    client = InferenceClient(api_key=api_key)

    # Filter and format data for energy demand by type
    type_demand = df[['type', 'demand']].groupby('type')['demand'].sum().reset_index()
    data_summary = type_demand.to_string(index=False)

    # Construct messages for chat completion
    messages = [
        {
            "role": "system",
            "content": "You are an expert energy consumption analyst."
        },
        {
            "role": "user",
            "content": f"Analyze the following energy consumption data. Provide a single, insightful paragraph covering patterns, trends, and recommendations. Mention the data if necessary:\n{data_summary}"
        }
    ]

    # Generate response
    response = client.chat.completions.create(
        model="Qwen/Qwen2.5-1.5B-Instruct",
        messages=messages,
        max_tokens=500
    )

    return response.choices[0].message.content

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({
            "success": False,
            "error": "Excel file path not provided"
        }))
        sys.exit(1)

    api_key = ""
    excel_path = sys.argv[1]
    
    try:
        analysis = process_energy_data_from_excel(excel_path, api_key)
        
        # Print raw analysis for debugging
        print("=== Raw Analysis Output ===")
        print(analysis)
        print("=== JSON Response ===")
        
        # Print JSON response for PHP
        print(json.dumps({
            "success": True,
            "summary": analysis
        }))
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))
        sys.exit(1)