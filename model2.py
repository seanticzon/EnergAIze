import json
import sys
from huggingface_hub import InferenceClient

def generate_combined_analysis(analyses: dict, api_key: str) -> dict:    
    building_analysis = analyses['building_analysis']
    type_analysis = analyses['type_analysis']

    # Initialize Inference Client
    client = InferenceClient(api_key=api_key)

    # Construct messages for overall summary
    summary_messages = [
        {
            "role": "system",
            "content": "You are an expert energy consumption analyst. Provide a comprehensive summary that combines insights from both building and type analyses."
        },
        {
            "role": "user",
            "content": f"""Based on the following analyses, provide a comprehensive executive summary that combines insights from both perspectives. Include key findings and recommendations:

Building Analysis:
{building_analysis}

Type Analysis:
{type_analysis}

Provide a cohesive summary that:
1. Synthesizes the key findings from both analyses
2. Provides 2-3 specific, actionable recommendations
3. Highlights any critical areas that need immediate attention"""
        }
    ]

    # Generate overall summary
    summary_response = client.chat.completions.create(
        model="Qwen/Qwen2.5-1.5B-Instruct",
        messages=summary_messages,
        max_tokens=250
    )

    return {
        "summary": summary_response.choices[0].message.content
    }

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({
            "success": False,
            "error": "Analyses file path not provided"
        }))
        sys.exit(1)

    api_key = ""
    analyses_file = sys.argv[1]
    
    try:
        analyses = generate_combined_analysis(analyses_file, api_key)
        
        # Print raw analysis for debugging
        print("=== Raw Analysis Output ===")
        print("Combined Analysis:", analyses["summary"])
        print("=== JSON Response ===")
        
        # Print JSON response for PHP
        print(json.dumps({
            "success": True,
            "summary": analyses["summary"]
        }))
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))
        sys.exit(1)