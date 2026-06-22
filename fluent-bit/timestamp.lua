function add_timestamp(tag, timestamp, record)
    -- Add timestamp if not present
    if not record["@timestamp"] then
        record["@timestamp"] = os.date("!%Y-%m-%dT%H:%M:%SZ")
    end
    
    -- Ensure environment is present
    if not record["environment"] then
        record["environment"] = os.getenv("APP_ENV") or "unknown"
    end
    
    -- Add system name if not present
    if not record["system"] then
        record["system"] = os.getenv("SYSTEM_NAME") or "cakephp-audit"
    end
    
    return 1, timestamp, record
end