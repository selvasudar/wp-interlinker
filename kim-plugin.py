from flask import Flask, request, jsonify
import json
import os
from threading import Thread
import threading
import requests
from bs4 import BeautifulSoup
import google.generativeai as genai
from urllib.parse import urlparse, urlunparse
import dropbox
import time
app = Flask(__name__)
model = genai.GenerativeModel('gemini-1.5-pro')
flash_model = genai.GenerativeModel('gemini-1.5-flash') #backup-model
genai.configure(api_key="AIzaSyBVHHs8EpDktqcq_pzdRiiCdkOmuJ-WgWs")

dropbox_file_path = '/Interlinker'  # Path in Dropbox
APP_KEY = "ez3emz94jvpjycc"
APP_SECRET = "e8caieekoua1h2m"

# Refresh token obtained from the one-time setup
REFRESH_TOKEN = "4LHFRxBleq0AAAAAAAAAAXpRLG7yd-FVc9WNQtKf7q-M9gMpfIYMd27HcEXUwfa4"

# Dropbox token endpoint
TOKEN_URL = "https://api.dropboxapi.com/oauth2/token"

def get_access_token():
    # Request payload for token refresh
    payload = {
        "grant_type": "refresh_token",
        "refresh_token": REFRESH_TOKEN,
    }

    # Send POST request to refresh the access token
    response = requests.post(
        TOKEN_URL,
        data=payload,
        auth=(APP_KEY, APP_SECRET)  # Basic Auth with App Key and Secret
    )

    if response.status_code == 200:
        token_data = response.json()
        access_token = token_data["access_token"]
        expires_in = token_data["expires_in"]
        # print(f"Access Token: {access_token}")
        # print(f"Expires In: {expires_in} seconds")
        return access_token
    else:
        print(f"Error: {response.status_code} - {response.text}")
        return None

def upload_to_dropbox(file_path, dropbox_path):
    dbx = dropbox.Dropbox(get_access_token())
    try:
        with open(file_path, 'rb') as inputfile:
            dbx.files_upload(inputfile.read(), dropbox_path+"/"+file_path, mode=dropbox.files.WriteMode.overwrite)
            print(f"File uploaded to Dropbox: {dropbox_path}")
    except dropbox.exceptions.ApiError as err:
        print(f"Failed to upload file: {err}")
    except FileNotFoundError:
        print(f"File not found: {file_path}")


# Function to get URLs from the sitemap
def get_sitemap_urls(sitemap_url):
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"
    }
    try:
        response = requests.get(sitemap_url, headers=headers)
        response.raise_for_status()
        soup = BeautifulSoup(response.content, "xml")
        urls = []
        for url in soup.find_all("url"):
            loc = url.find("loc")
            if loc:
                urls.append(loc.text)
        return urls

    except Exception as e:
        return {"error": str(e)}
        
def get_folder_name_from_sitemap(sitemap_url):
    # Parse the URL to extract the domain or specific part of the path
    parsed_url = urlparse(sitemap_url)
    domain = parsed_url.netloc  # Extract the domain (e.g., kissflow.com)
    path = parsed_url.path  # Get the path (e.g., /ebooks/sitemap.xml)
    
    # Extract the company name from the domain (e.g., 'kissflow' from 'kissflow.com')
    folder_name = domain.split('.')[0]
    
    # Extract the subdirectory name before the .xml file
    path_parts = path.strip('/').split('/')  # Split path into parts
    for part in path_parts:
        if part.endswith('.xml'):
            break
        folder_name += f"_{part}"  # Append each directory before the XML file
    
    return folder_name

# Function to process the sitemap
def process_sitemap_in_thread(sitemap_url):    
    # Step 1: Get URLs from the sitemap
    urls = get_sitemap_urls(sitemap_url)
    if isinstance(urls, dict) and "error" in urls:
        print(f"Error fetching sitemap: {urls['error']}")
        return

    # Load existing keywords data
    keywords_file = "keywords.json"
    existing_keywords = {}
    if os.path.exists(keywords_file):
        try:
            with open(keywords_file, "r") as f:
                content = f.read().strip()  # Read and strip whitespace
                if content:  # Ensure the file is not empty
                    existing_keywords = json.loads(content)
                else:
                    print(f"{keywords_file} is empty. Starting with an empty dictionary.")
        except json.JSONDecodeError as e:
            print(f"Error decoding JSON from {keywords_file}: {e}. Starting with an empty dictionary.")

    results = []
    for url in urls:
        if url in [entry["url"] for entry in existing_keywords.get("success_urls", [])]:
            print(f"URL already processed: {url}")
            continue
        # time.sleep(10)
        # Extract primary keyword
        primary_keyword = extract_keywords_from_url(url)
        if isinstance(primary_keyword, dict) and "error" in primary_keyword:
            print(f"Error extracting keywords for {url}: {primary_keyword['error']}")
            continue

        # Append the result
        new_entry = {"url": url, "primary_keyword": primary_keyword}
        existing_keywords["success_urls"].append(new_entry)

        # Write updated data to the JSON file
        with open(keywords_file, "w") as f:
            json.dump(existing_keywords, f, indent=4)

        print(f"Processed URL: {url}, saved to {keywords_file}")

    print(f"Sitemap processing completed. Results saved to {keywords_file}.")
    upload_to_dropbox(keywords_file, dropbox_file_path)
    # process_interlinking(sitemap_url)


# Function to extract keywords from a URL
def extract_keywords_from_url(url):
    prompt = f"Consider yourself as a SEO Expert. Generate a single primary keyword from the url {url} after analysing the heading tags like h1,h2,h3,h4,h5,h6 and intent of the content.If there is a meta description and title exists in the page, take that into consideration.verify the keyword with content of the page in a semantic way as well.Give response in the proper json format.",
    try:
        print(f"Processing URL: {url}")
        # Replace this with actual Gemini API call
        time.sleep(32)
        response = model.generate_content(prompt)
        return extract_primary_keyword_from_response(response)
    except Exception as e:
        print("failed response")
        response = flash_model.generate_content(prompt)
        print("response",response)
        return extract_primary_keyword_from_response(response)
        return {"error": str(e)}

# Function to extract primary keyword from Gemini response
def extract_primary_keyword_from_response(genai_response):
    try:
        # Initialize response_text as empty
        response_text = ""

        # Check for the structure of the response and extract the text
        if "result" in genai_response and "candidates" in genai_response.result:
            response_text = genai_response.result["candidates"][0]["content"]["parts"][0]["text"].strip()
        else:
            response_text = genai_response.text.strip()

        # if response_text.startswith("```json") and response_text.endswith("```"):
        if response_text.startswith("```json") and (response_text.endswith("```") or response_text.endswith("```\n")):
            response_text = response_text[7:-3].strip()
        parsed_data = json.loads(response_text)
        return parsed_data.get("primary_keyword", parsed_data.get("primaryKeyword", ""))
    except Exception as e:
        return {"error": str(e)}

@app.route('/process-sitemap', methods=['POST'])
def process_sitemap():
    data = request.json
    sitemap_url = data.get("sitemap_url")
    if not sitemap_url:
        print("Success API input error")
        return jsonify({
            "success": False,
            "StatusCode":400,
            "data": {
                "message": "Sitemap URL is required"
            }
        }), 400
    
    try:
        dropbox_file_path = get_folder_name_from_sitemap(sitemap_url)
        sitemap_thread = threading.Thread(target=process_sitemap_in_thread, args=(sitemap_url,))
        sitemap_thread.start()
        print("Success API Call")
        return jsonify({
            "success": True,
            "StatusCode":200,
            "data": {
                "message": "Sitemap processing initiated."
            }
        }), 200
        
    except Exception as e:
        print("Success API Exception")
        return jsonify({
            "success": False,
            "StatusCode":500,            
            "data": {
                "message": str(e)
            }
        }), 500


# @app.route('/process-sitemap', methods=['POST'])
# def process_sitemap():
#     data = request.json
#     sitemap_url = data.get("sitemap_url")
#     dropbox_file_path = get_folder_name_from_sitemap(sitemap_url)
#     if not sitemap_url:
#         return jsonify({"error": "Sitemap URL is required"}), 400
    
#     # sitemap_thread = threading.Thread(target=process_sitemap_in_thread, args=(sitemap_url,))
#     # sitemap_thread.start()

#     return jsonify({"success": True,"message": "Sitemap processing initiated."}), 200



# Function to save interlink data to a JSON file
def save_interlink_data(data, filename="interlink_data.json"):
    with open(filename, "w") as file:
        json.dump(data, file, indent=4)

# Function to load keywords from a JSON file
def load_keywords_from_json(filename="keywords.json"):
    with open(filename, "r") as file:
        return json.load(file)

def fetch_page_content(url):
    try:
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"
        }
        response = requests.get(url, headers=headers)
        response.raise_for_status()
        
        # Parse the HTML content
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Remove unwanted elements
        for tag in soup.find_all(['script', 'link', 'header', 'footer']):
            tag.decompose()
            
        # Get the main content
        main_content = soup.find('main') or soup.find('body')
        
        if main_content:
            # Clean up the content
            # Remove extra whitespace and normalize spacing
            cleaned_text = ' '.join(main_content.get_text(separator=' ').split())
            return cleaned_text
        else:
            return ' '.join(soup.get_text(separator=' ').split())
            
    except Exception as e:
        raise Exception(f"Failed to fetch content for {url}: {str(e)}")

def find_optimal_interlink_positions(content, keyword, url):
    # First, parse the HTML content
    soup = BeautifulSoup(content, 'html.parser')
    # Get readable text content
    text_content = soup.get_text(separator=' ', strip=True)
    
    prompt = (
        f"I am an SEO expert, and I need to interlink keywords effectively. "
        f"Analyze this content and identify up to 2 natural places where the keyword '{keyword}' "
        f"can be interlinked. The sentences must exist exactly in the content - do not modify them. "
        f"The URL to link to is: {url}\n\n"
        f"Content to analyze: {text_content[:4000]}\n\n"  # Limit content length
        "Return JSON with exactly these fields for each position: "
        "{'keyword': 'exact keyword', 'sentence': 'full sentence from content', 'position': 'exact phrases in the sentence to be interlinked which should be maximum of 3 words in length'}"
    )
    
    try:
        genai_response = model.generate_content(prompt)
        response_text = genai_response.text.strip()
        
        # Clean up the response similar to extract_primary_keyword_from_response
        if response_text.startswith("```json") and response_text.endswith("```"):
            response_text = response_text[7:-3].strip()
            
        # Parse the JSON response
        parsed_data = json.loads(response_text)
        
        # Ensure the response is a list
        if isinstance(parsed_data, dict):
            parsed_data = [parsed_data]
            
        # Validate that each sentence actually exists in the content
        validated_positions = []
        for position in parsed_data:
            if position['sentence'] in text_content:
                validated_positions.append(position)
                
        return validated_positions
        
    except Exception as e:
        print(f"Error in find_optimal_interlink_positions: {e}")
        return []  # Return empty list instead of raising exception

def normalize_url(url):
    parsed_url = urlparse(url)
    # Remove fragments and normalize
    return urlunparse(parsed_url._replace(fragment=""))

# Main logic
def process_interlinking(sitemap_url):    
    # Load keywords from JSON
    stored_keywords = load_keywords_from_json()
    interlink_data = []
    
    # Get all sitemap URLs
    sitemap_urls = get_sitemap_urls(sitemap_url)
    if isinstance(sitemap_urls, dict) and "error" in sitemap_urls:
        print(f"Error fetching sitemap: {sitemap_urls['error']}")
        return    

    # First loop: Iterate through each keyword from keywords.json
    for keyword_entry in stored_keywords['success_urls']:
        keyword = keyword_entry['primary_keyword']
        keyword_url = keyword_entry['url']
        print(f"\nChecking interlinking opportunities for keyword: {keyword}")
        
        # Second loop: Check this keyword against all URLs in sitemap
        for target_url in sitemap_urls:
            # Skip if target URL is the same as keyword's source URL
            if normalize_url(target_url) == normalize_url(keyword_url):
                continue
                
            print(f"Analyzing URL: {target_url}")
            try:
                # Fetch and analyze content of target URL
                content = fetch_page_content(target_url)
                positions = find_optimal_interlink_positions(content, keyword, keyword_url)

                for position in positions:
                    interlink_data.append({
                        "keyword": keyword,
                        "sentence": position["sentence"],
                        "position": position["position"],
                        "target_url": target_url,  # URL where we found the opportunity
                        "source_url": keyword_url  # URL we'll link to
                    })

                # Save interlink data incrementally
                save_interlink_data(interlink_data)

            except Exception as e:
                print(f"Failed to process {target_url}: {e}")
    
    upload_to_dropbox("interlink_data.json", dropbox_file_path)
    print(f"Completed interlinking analysis. Found {len(interlink_data)} opportunities.")

            
if __name__ == "__main__":
    app.run(debug=True)
